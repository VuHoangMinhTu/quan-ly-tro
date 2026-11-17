import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "react-router-dom";
import {
  ChevronLeft,
  ChevronRight,
  Pencil,
  Search,
  X,
} from "lucide-react";
import { getBoardingHouses } from "../../api/boardingHouseApi";
import { getUtilityLedger } from "../../api/utilityLedgerApi";
import { confirmBillingPeriodBoundaries } from "../../api/billingV2Api";
import { getApiErrorMessage } from "../../api/getApiErrorMessage";
import UtilityLedgerHistory from "./UtilityLedgerHistory";
import { display, formatTime, readingError, usageStatus } from "./utilityLedgerDisplay.mjs";

const currentYear = new Date().getFullYear();
const localDateTime = () =>
  new Date()
    .toLocaleString("sv-SE", { timeZone: "Asia/Ho_Chi_Minh" })
    .replace(" ", "T")
    .slice(0, 16);
const apiTime = (value) => value.replace("T", " ") + (value.length === 16 ? ":00" : "");
const monthKey = (year, month) =>
  `${year}-${String(month).padStart(2, "0")}-01`;
const meterKind = (meter) =>
  meter.service?.type === "ELECTRICITY"
    ? "electric"
    : meter.service?.type === "WATER"
      ? "water"
      : "other";
const statuses = ["Tất cả", "Chưa ghi", "Ghi một phần", "Đã ghi", "Cần đối soát"];

function usageFor(periods, meterId, period) {
  const row = periods.find(
    (item) =>
      Number(item.utility_meter_id) === Number(meterId) &&
      item.billing_period === period,
  );
  return row ? Number(row.quantity) : null;
}

function sumUsage(periods, meters, period, kind) {
  const quantities = meters.filter(meter => meterKind(meter) === kind)
    .map(meter => usageFor(periods, meter.id, period)).filter(quantity => quantity !== null);
  return quantities.length ? quantities.reduce((sum, quantity) => sum + quantity, 0) : null;
}

function readingPayload(meter, period, end, value, start) {
  return {
    billing_period: period.key,
    start_observed_at: start ? apiTime(start) : meter.previousAt || apiTime(period.start),
    end_observed_at: apiTime(end),
    start_reading_value: meter.previous,
    end_reading_value: value,
  };
}

function MeterInputs({ meters, initial, onChange, dates, setDate, observedAt, period }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {meters.map((meter) => (
        <section
          key={meter.id}
          className="rounded-xl border border-slate-200 p-3"
        >
          <p className={`font-semibold ${meterKind(meter) === "electric" ? "text-amber-800" : "text-sky-800"}`}>
            {meter.service?.name || "Công tơ"}{" "}
            <span className="text-xs font-normal text-slate-500">
              {meter.meter_code || `#${meter.id}`}
            </span>
          </p>
          <p className="mt-2 text-sm">Chỉ số cũ: {display(meter.previous)}</p>
          <p className="mt-1 text-xs text-slate-600">
            {meter.previousAt && !dates[meter.id]?.start ? "Mốc đã xác nhận được đề xuất" : "Mốc đầu kỳ cần xác nhận"}
            {" · "}{formatTime(dates[meter.id]?.start || meter.previousAt || period.start)}
            {" → "}{formatTime(dates[meter.id]?.end ?? observedAt)}
          </p>
          <label className="mt-2 block text-sm font-medium text-slate-700">
            Chỉ số mới
            <input
              inputMode="decimal"
              type="number"
              min="0"
              step="0.01"
              aria-invalid={Boolean(readingError(initial[meter.id], meter.previous))}
              value={initial[meter.id] ?? ""}
              onChange={(event) => onChange(meter.id, event.target.value)}
              className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"
            />
          </label>
          {readingError(initial[meter.id], meter.previous) ? <p role="alert" className="mt-2 text-sm text-red-700">{readingError(initial[meter.id], meter.previous)}</p> : initial[meter.id] != null && initial[meter.id] !== "" && (
            <p className="mt-2 rounded bg-teal-50 px-2 py-1 font-semibold text-teal-900">Tiêu thụ: {display(Number(initial[meter.id]) - Number(meter.previous))} {meter.service?.unit}</p>
          )}
          <details className="mt-2 text-sm">
            <summary>Ngày giờ của công tơ</summary>
            <label className="block">Đầu kỳ
              <input type="datetime-local" step="1" className="block w-full" value={dates[meter.id]?.start ?? (meter.previousAt?.replace(" ", "T") || period.start)}
                onChange={event => setDate(meter.id, "start", event.target.value)} />
            </label>
            <label className="block">Cuối kỳ
              <input type="datetime-local" step="1" className="block w-full" value={dates[meter.id]?.end ?? observedAt}
                onChange={event => setDate(meter.id, "end", event.target.value)} />
            </label>
          </details>
        </section>
      ))}
    </div>
  );
}

function ReadingDialog({ room, meters: initialMeters, period, onClose, onSaved, onNext, onPrevious, position, total }) {
  const [meters] = useState(initialMeters);
  const [readings, setReadings] = useState({});
  const [dates, setDates] = useState({});
  const [observedAt, setObservedAt] = useState(localDateTime());
  const [error, setError] = useState("");
  const invalid = meters.some(meter => readingError(readings[meter.id], meter.previous));
  const mutation = useMutation({
    mutationFn: async () => {
      const results = [];
      if (invalid) throw new Error("Kiểm tra lại chỉ số được đánh dấu.");
      for (const meter of meters) {
        const next = readings[meter.id];
        if (next === undefined || next === "") continue;
        results.push(
          confirmBillingPeriodBoundaries(meter.id, readingPayload(meter, period, dates[meter.id]?.end ?? observedAt, next, dates[meter.id]?.start)),
        );
      }
      if (!results.length)
        throw new Error("Nhập ít nhất một chỉ số mới trước khi lưu.");
      return Promise.all(results);
    },
    onSuccess: (_, advance) => {
      onSaved();
      if (advance) onNext();
    },
    onError: (err) => setError(getApiErrorMessage(err)),
  });
  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="reading-title"
      className="fixed inset-0 z-50 grid place-items-center bg-slate-950/45 p-3"
    >
      <div className="max-h-[92dvh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="reading-title" className="text-xl font-bold text-slate-950">
              Ghi chỉ số · {room.room_code}
            </h2>
            <p className="mt-1 text-sm text-slate-600">
              {room.tenant || "Chưa có người thuê"} · Kỳ {period.label} · Phòng {position}/{total}
            </p>
            <p className="mt-1 text-xs text-slate-600">Kiểm tra khoảng đo trước khi lưu. Sản lượng thuộc kỳ đang chọn.</p>
          </div>
          <button
            aria-label="Đóng"
            type="button"
            onClick={onClose}
            className="grid size-11 place-items-center rounded-lg text-slate-600 hover:bg-slate-100"
          >
            <X />
          </button>
        </div>
        <label className="mt-4 block text-sm font-medium text-slate-700">
          Ngày giờ ghi
          <input
            type="datetime-local"
            value={observedAt}
            onChange={(event) => setObservedAt(event.target.value)}
            className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"
          />
        </label>
        <div className="mt-4">
          <MeterInputs
            meters={meters}
            initial={readings}
            dates={dates}
            observedAt={observedAt}
            period={period}
            setDate={(id, field, value) => setDates(state => ({
              ...state, [id]: { ...state[id], [field]: value },
            }))}
            onChange={(id, value) =>
              setReadings((state) => ({ ...state, [id]: value }))
            }
          />
        </div>
        {error && (
          <p
            role="alert"
            className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-800"
          >
            {error}
          </p>
        )}
        <div className="mt-5 flex flex-wrap justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            className="min-h-11 rounded-lg px-4 font-semibold text-slate-700 hover:bg-slate-100"
          >
            Đóng
          </button>
          <button
            type="button"
            disabled={mutation.isPending || invalid}
            onClick={() => mutation.mutate()}
            className="min-h-11 rounded-lg bg-teal-700 px-5 font-semibold text-white disabled:opacity-60"
          >
            {mutation.isPending ? "Đang lưu…" : "Lưu chỉ số"}
          </button>
          {onPrevious && <button type="button" disabled={mutation.isPending} onClick={onPrevious} className="min-h-11 rounded-lg border px-3">Phòng trước</button>}
          {onNext && <button type="button" disabled={mutation.isPending} onClick={onNext} className="min-h-11 rounded-lg border px-3">Bỏ qua phòng</button>}
          {onNext && <button type="button" disabled={mutation.isPending || invalid}
            onClick={() => mutation.mutate(true)}
            className="min-h-11 rounded-lg bg-teal-700 px-4 font-semibold text-white">
            Lưu & sang phòng tiếp theo
          </button>}
          {mutation.isSuccess && <p role="status">Đã lưu chỉ số cho {room.room_code}.</p>}
        </div>
      </div>
    </div>
  );
}

function BulkDialog({ rooms, metersByRoom: initialMetersByRoom, period, onClose, onSaved, statusFor, contracts }) {
  const [metersByRoom] = useState(initialMetersByRoom);
  const [values, setValues] = useState({});
  const [selected, setSelected] = useState(
    () => new Set(rooms.map((room) => room.id)),
  );
  const [outcome, setOutcome] = useState({});
  const [busy, setBusy] = useState(false);
  const [observedAt, setObservedAt] = useState(localDateTime);
  const [meterDates, setMeterDates] = useState({});
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState(statuses[0]);
  const shown = rooms.filter(room =>
    `${room.room_code} ${contracts[room.id] || ""}`.toLowerCase().includes(search.trim().toLowerCase()) &&
    (filter === statuses[0] || statusFor(room) === filter));
  const completed = Object.values(outcome).filter(value => value === "Đã lưu").length;
  const save = async (retry = false) => {
    setBusy(true);
    const next = {};
    for (const room of rooms.filter((item) => selected.has(item.id))) {
      for (const meter of metersByRoom[room.id] || []) {
        if (meterKind(meter) === "other") continue;
        const value = values[meter.id];
        if (value == null || value === "" || outcome[meter.id] === "Đã lưu" || (retry && !outcome[meter.id])) continue;
        const invalid = readingError(value, meter.previous);
        if (invalid) {
          next[meter.id] = invalid;
          continue;
        }
        try {
          await confirmBillingPeriodBoundaries(meter.id, readingPayload(meter, period, meterDates[meter.id] || observedAt, value), { quiet: true });
          next[meter.id] = "Đã lưu";
        } catch (error) {
          next[meter.id] = getApiErrorMessage(error);
        }
      }
    }
    setOutcome((current) => ({ ...current, ...next }));
    onSaved();
    setBusy(false);
  };
  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="bulk-title"
      className="fixed inset-0 z-50 bg-slate-950/45 p-0 sm:grid sm:place-items-center sm:p-4"
    >
      <div className="flex h-full w-full flex-col bg-white sm:h-auto sm:max-h-[92dvh] sm:max-w-6xl sm:rounded-2xl sm:shadow-2xl">
        <header className="flex items-center justify-between border-b border-slate-200 p-4">
          <div>
            <h2 id="bulk-title" className="text-xl font-bold">
              Ghi chỉ số hàng loạt
            </h2>
            <p className="text-sm text-slate-600">
              Kỳ {period.label} · {selected.size} phòng đã chọn · {completed} công tơ đã lưu
            </p>
          </div>
          <button
            aria-label="Đóng"
            type="button"
            onClick={onClose}
            className="grid size-11 place-items-center rounded-lg hover:bg-slate-100"
          >
            <X />
          </button>
        </header>
        <div className="min-h-0 flex-1 overflow-auto p-4">
          <label className="mb-3 block text-sm">Ngày giờ ghi mặc định
            <input type="datetime-local" value={observedAt} disabled={busy}
              onChange={event => setObservedAt(event.target.value)} className="ml-2 rounded border p-2" />
          </label>
          <div className="mb-3 flex flex-wrap gap-2">
            <input aria-label="Tìm phòng nhập hàng loạt" placeholder="Tìm phòng hoặc người thuê" className="min-h-11 flex-1 rounded border px-3"
              value={search} onChange={event => setSearch(event.target.value)} />
            <select aria-label="Lọc phòng nhập hàng loạt" value={filter} onChange={event => setFilter(event.target.value)} className="min-h-11 rounded border px-3">
              {statuses.map(status => <option key={status}>{status}</option>)}
            </select>
          </div>
          <table className="block w-full text-sm sm:table sm:min-w-[760px]">
            <thead className="hidden sm:table-header-group sticky top-0 bg-white text-left text-slate-600">
              <tr className="border-b">
                <th className="p-2">
                  <input
                    aria-label="Chọn tất cả"
                    type="checkbox"
                    checked={selected.size === rooms.length}
                    onChange={(event) =>
                      setSelected(
                        event.target.checked
                          ? new Set(rooms.map((r) => r.id))
                          : new Set(),
                      )
                    }
                  />
                </th>
                <th className="p-2">Phòng</th>
                {["Điện", "Nước"].map((label, index) => <th key={label} className={`p-2 text-center ${index ? "text-sky-800" : "text-amber-800"}`}>
                  {label}<div className="mt-1 grid grid-cols-[1fr_1.4fr_1fr] gap-2 text-right text-xs font-normal"><span>Cũ</span><span>Mới</span><span>Tiêu thụ</span></div>
                </th>)}
                <th className="p-2">Trạng thái</th>
              </tr>
            </thead>
            <tbody className="block sm:table-row-group">
              {shown.map((room) => {
                const list = (metersByRoom[room.id] || []).filter(meter => meterKind(meter) !== "other");
                const input = (meter) => {
                  const invalid = readingError(values[meter.id], meter.previous);
                  return (
                    <div className="grid grid-cols-[1fr_1.4fr_1fr] items-center gap-2 text-right tabular-nums">
                      <span className={`col-span-3 text-left font-medium sm:hidden ${meterKind(meter) === "electric" ? "text-amber-800" : "text-sky-800"}`}>{meter.service?.name}</span>
                      <span>
                        {display(meter.previous ?? meter.initial_reading ?? 0)}
                      </span>
                      <input
                        aria-label={`Chỉ số mới ${room.room_code} ${meter.service?.name || ""}`}
                        inputMode="decimal"
                        type="number"
                        aria-invalid={Boolean(invalid)}
                        value={values[meter.id] ?? ""}
                        disabled={busy || outcome[meter.id] === "Đã lưu"}
                        onChange={(event) =>
                          setValues((state) => ({
                            ...state,
                            [meter.id]: event.target.value,
                          }))
                        }
                        className="min-h-11 w-full min-w-0 appearance-[textfield] rounded border border-slate-300 px-1 py-2 text-right aria-invalid:border-red-600 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                      />
                      <span>
                        {values[meter.id] === undefined ||
                        values[meter.id] === "" || invalid
                          ? "—"
                          : display(
                              Number(values[meter.id]) -
                                Number(
                                  meter.previous ?? meter.initial_reading ?? 0,
                                ),
                            )}
                      </span>
                      {invalid && <p role="alert" className="col-span-3 text-left text-xs text-red-700">{invalid}</p>}
                      <details className="col-span-3 text-left text-xs">
                        <summary>Giờ ghi</summary>
                        <input type="datetime-local" value={meterDates[meter.id] ?? observedAt}
                          aria-label={`Giờ ghi ${room.room_code} ${meter.service?.name || ""}`}
                          disabled={busy || outcome[meter.id] === "Đã lưu"}
                          onChange={event => setMeterDates(state => ({ ...state, [meter.id]: event.target.value }))} />
                      </details>
                    </div>
                  );
                };
                return (
                  <tr key={room.id} className="mb-3 flex flex-wrap rounded-lg border border-slate-200 sm:table-row sm:border-x-0 sm:border-t-0">
                    <td className="p-2 sm:table-cell">
                      <input
                        aria-label={`Chọn ${room.room_code}`}
                        type="checkbox"
                        checked={selected.has(room.id)}
                        onChange={(event) =>
                          setSelected((state) => {
                            const result = new Set(state);
                            if (event.target.checked) {
                              result.add(room.id);
                            } else {
                              result.delete(room.id);
                            }
                            return result;
                          })
                        }
                      />
                    </td>
                    <td className="w-[calc(100%-3rem)] grow p-2 font-semibold sm:w-auto">{room.room_code}<div className="text-xs font-normal text-slate-600">{contracts[room.id] || "Chưa thuê"}</div></td>
                    <td className="w-full px-2 py-1 min-[370px]:w-1/2 sm:w-auto">{list.filter(m => meterKind(m) === "electric").map(m => <div key={m.id}>{input(m)}</div>)}</td>
                    <td className="w-full px-2 py-1 min-[370px]:w-1/2 sm:w-auto">{list.filter(m => meterKind(m) === "water").map(m => <div key={m.id}>{input(m)}</div>)}</td>
                    <td className="w-full p-2 sm:w-auto">{list.map(m => <div key={m.id}>{m.meter_code || m.service?.name}: {outcome[m.id] || "Chưa lưu"}</div>)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <footer className="flex justify-end gap-2 border-t border-slate-200 p-4">
          <button
            type="button"
            onClick={onClose}
            className="min-h-11 rounded-lg px-4 font-semibold"
          >
            Đóng
          </button>
          <button
            type="button"
            onClick={() => save()}
            disabled={busy}
            className="min-h-11 rounded-lg bg-teal-700 px-5 font-semibold text-white"
          >
            Lưu tất cả
          </button>
          <button type="button" disabled={busy || !Object.values(outcome).some(value => value !== "Đã lưu")}
            onClick={() => save(true)} className="min-h-11 rounded-lg border px-4">
            Thử lại mục lỗi
          </button>
        </footer>
      </div>
    </div>
  );
}

export default function UtilityLedgerPage() {
  const [params, setParams] = useSearchParams();
  const savedMonth = params.get("period");
  const [year, setYear] = useState(savedMonth && /^20\d{2}-(0[1-9]|1[0-2])$/.test(savedMonth) ? Number(savedMonth.slice(0, 4)) : currentYear);
  const [recordMonth, setRecordMonth] = useState(
    () =>
      savedMonth && /^20\d{2}-(0[1-9]|1[0-2])$/.test(savedMonth)
        ? savedMonth
        : `${currentYear}-${String(new Date().getMonth() + 1).padStart(2, "0")}`,
  );
  const [tab, setTab] = useState("all");
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState(statuses[0]);
  const [selectedRoom, setSelectedRoom] = useState(null);
  const [dialogRoom, setDialogRoom] = useState(null);
  const [bulk, setBulk] = useState(false);
  const houses = useQuery({
    queryKey: ["boarding-houses"],
    queryFn: getBoardingHouses,
  });
  const houseId = params.get("house") || "";
  const ledger = useQuery({
    queryKey: ["utility-ledger", houseId, year],
    queryFn: () => getUtilityLedger(houseId, year),
    enabled: Boolean(houseId),
  });
  const client = useQueryClient();
  const raw = ledger.data?.data?.data;
  const data = useMemo(() => {
    if (!raw) return null;
    const points = new Map(raw.checkpoints.map(point => [Number(point.id), point]));
    const periods = new Map(raw.periods.map(period => {
      const start = points.get(Number(period.start_checkpoint_id));
      const end = points.get(Number(period.end_checkpoint_id));
      return [`${period.utility_meter_id}:${period.billing_period}`, {
        ...period, start, end, settled: true, correctable: Boolean(start && end),
        needsReview: Boolean(period.start_checkpoint_id && (!start || !end)),
      }];
    }));
    for (const link of raw.boundaries) {
      const start = points.get(Number(link.start_checkpoint_id));
      const end = points.get(Number(link.end_checkpoint_id));
      if (!start || !end) continue;
      const key = `${link.utility_meter_id}:${link.billing_period}`;
      const settled = periods.get(key);
      periods.set(key, {
        ...link, start, end,
        quantity: Number(end.reading_value) - Number(start.reading_value),
        correctable: true,
        settled: Boolean(settled),
        needsReview: Boolean(settled && (Number(settled.start_checkpoint_id) !== Number(start.id) ||
          Number(settled.end_checkpoint_id) !== Number(end.id))),
      });
    }
    return { ...raw, periods: [...periods.values()] };
  }, [raw]);
  const rooms = data?.rooms || [];
  const metersByRoom = useMemo(
    () =>
      (data?.meters || [])
        .map((meter) => {
          // The starting point is only a suggestion from previously confirmed V2
          // evidence. It does not declare or infer a billing period.
          const previous = (data?.checkpoints || [])
            .filter(
              (point) =>
                Number(point.utility_meter_id) === Number(meter.id) &&
                point.observed_at <= `${recordMonth}-01 00:00:00`,
            )
            .sort((a, b) => a.observed_at.localeCompare(b.observed_at))
            .at(-1);
          return {
            ...meter,
            previous: previous?.reading_value ?? meter.initial_reading,
            previousAt: previous?.observed_at,
          };
        })
        .reduce((result, meter) => {
          (result[meter.room_id] ||= []).push(meter);
          return result;
        }, {}),
    [data, recordMonth],
  );
  const contracts = useMemo(
    () =>
      Object.fromEntries(
        (data?.active_contracts || []).map((contract) => [
          contract.room_id,
          contract.tenant?.full_name,
        ]),
      ),
    [data],
  );
  const period = {
    key: `${recordMonth}-01`,
    start: `${recordMonth}-01T00:00`,
    label: `tháng ${Number(recordMonth.slice(5))}/${recordMonth.slice(0, 4)}`,
  };
  const statusFor = room => {
    const ids = new Set((metersByRoom[room.id] || []).filter(meter => meterKind(meter) !== "other").map(meter => Number(meter.id)));
    return usageStatus(data.periods.filter(row => row.billing_period === period.key && ids.has(Number(row.utility_meter_id))), ids.size);
  };
  const visible = rooms.filter(room => room.room_code.toLowerCase().includes(search.toLowerCase()) &&
    (filter === statuses[0] || statusFor(room) === filter));
  const writable = room => data?.can_record && room.room_type === "PRIVATE_ROOM" &&
    (metersByRoom[room.id] || []).some(meter => meterKind(meter) !== "other");
  const openRoom = (room) =>
    setDialogRoom({ ...room, tenant: contracts[room.id] });
  const queue = rooms.filter(writable);
  const position = queue.findIndex(room => room.id === dialogRoom?.id);
  const nextRoom = position >= 0 ? queue[position + 1] : null;
  const previousRoom = position > 0 ? queue[position - 1] : null;
  const saved = () =>
    client.invalidateQueries({ queryKey: ["utility-ledger", houseId, year] });
  if (houses.isPending || (houseId && ledger.isPending))
    return (
      <p role="status" className="p-5 text-slate-600">
        Đang tải sổ điện nước…
      </p>
    );
  if (houses.isError || ledger.isError)
    return (
      <p role="alert" className="rounded-lg bg-red-50 p-4 text-red-800">
        {getApiErrorMessage(houses.error || ledger.error)}
      </p>
    );
  return (
    <>
      <section className="mx-auto max-w-[1500px] space-y-5">
        <header className="rounded-2xl bg-gradient-to-r from-[#072946] via-[#0c4f63] to-teal-500 p-5 text-white shadow-sm">
          <p className="text-sm font-medium text-teal-100">
            BeeHouse · Điện nước
          </p>
          <h1 className="mt-1 text-2xl font-bold tracking-tight">
            Sổ điện nước
          </h1>
          <p className="mt-1 text-sm text-teal-50">
            Ghi chỉ số mới ngay trên danh sách phòng, không tạo hóa đơn.
          </p>
        </header>
        <div className="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4">
          <label className="min-w-56 flex-1 text-sm font-semibold text-slate-700">
            Nhà trọ
            <select
              aria-label="Nhà trọ"
              value={houseId}
              onChange={(event) => {
                const id = event.target.value;
                setParams(id ? { house: id, period: recordMonth } : {}, { replace: true });
                setTab("all");
                setSelectedRoom(null);
              }}
              className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3"
            >
              <option value="">Chọn nhà trọ</option>
              {(houses.data?.data?.data || []).map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
          </label>
          <div className="flex items-center gap-1 rounded-lg border border-slate-300 p-1">
            <button
              aria-label="Năm trước"
              onClick={() => setYear((value) => value - 1)}
              className="grid size-10 place-items-center rounded hover:bg-slate-100"
            >
              <ChevronLeft />
            </button>
            <strong className="px-2">Năm {year}</strong>
            <button
              aria-label="Năm sau"
              onClick={() => setYear((value) => value + 1)}
              className="grid size-10 place-items-center rounded hover:bg-slate-100"
            >
              <ChevronRight />
            </button>
          </div>
          <label className="text-sm font-semibold text-slate-700">
            Kỳ đang ghi
            <input
              type="month"
              value={recordMonth}
              onChange={(event) => {
                if (!event.target.value) return;
                setRecordMonth(event.target.value);
                setYear(Number(event.target.value.slice(0, 4)));
                setParams({ house: houseId, period: event.target.value }, { replace: true });
              }}
              className="mt-1 min-h-11 rounded-lg border border-slate-300 px-3"
            />
          </label>
          <button
            type="button"
              disabled={!visible.some(writable)}
              onClick={() => setBulk(true)}
            className="min-h-11 rounded-lg bg-teal-700 px-4 font-semibold text-white hover:bg-teal-800"
          >
            + Ghi chỉ số hàng loạt
          </button>
        </div>
        <nav
          className="flex border-b border-slate-200"
          aria-label="Chế độ xem sổ điện nước"
        >
          <button
            type="button"
            aria-pressed={tab === "all"}
            onClick={() => setTab("all")}
            className={`min-h-11 border-b-2 px-4 font-semibold ${tab === "all" ? "border-teal-600 text-teal-700" : "border-transparent text-slate-600"}`}
          >
            Cả nhà
          </button>
          <button
            type="button"
            aria-pressed={tab === "room"}
            onClick={() => setTab("room")}
            className={`min-h-11 border-b-2 px-4 font-semibold ${tab === "room" ? "border-teal-600 text-teal-700" : "border-transparent text-slate-600"}`}
          >
            Từng phòng
          </button>
        </nav>
        {!houseId ? <p className="rounded-xl border border-slate-200 bg-white p-6 text-slate-600">Chọn nhà trọ để xem phòng và ghi chỉ số điện nước.</p> : tab === "all" ? (
          <>
            <div className="flex flex-wrap gap-3">
              <label className="relative min-w-64 flex-1">
                <span className="sr-only">Tìm phòng</span>
                <Search
                  className="pointer-events-none absolute left-3 top-3 text-slate-400"
                  size={20}
                />
                <input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Tìm phòng"
                  className="min-h-11 w-full rounded-lg border border-slate-300 pl-10 pr-3"
                />
              </label>
              <select aria-label="Trạng thái ghi chỉ số" value={filter} onChange={event => setFilter(event.target.value)} className="min-h-11 rounded-lg border border-slate-300 bg-white px-3">
                {statuses.map(status => <option key={status}>{status}</option>)}
              </select>
              <p className="rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-900">
                {visible.length} phòng · kỳ đang ghi: {period.label}
              </p>
            </div>
            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
              <table className="min-w-[1200px] w-full border-collapse text-sm">
                <thead className="bg-slate-50 text-slate-700">
                  <tr>
                    <th className="sticky left-0 z-10 bg-slate-50 p-3 text-left">
                      Phòng
                    </th>
                    {Array.from({ length: 12 }, (_, index) => (
                      <th key={index} className={`min-w-16 p-2 text-center ${monthKey(year, index + 1) === period.key ? "bg-teal-100 text-teal-900" : ""}`}>
                        T{index + 1}
                      </th>
                    ))}
                    <th className="bg-slate-50 p-3">
                      Thao tác
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {visible.map((room) => (
                    <tr
                      key={room.id}
                      className="border-t border-slate-100 hover:bg-teal-50/30"
                    >
                      <td className="sticky left-0 z-10 bg-white p-3 font-bold text-teal-700">
                        <button
                          onClick={() => {
                            setTab("room");
                            setSelectedRoom({ ...room, tenant: contracts[room.id] });
                          }}
                          className="underline decoration-teal-300 underline-offset-4"
                        >
                          {room.room_code}
                        </button>
                        <p className="mt-1 text-xs font-normal text-slate-500">
                          {contracts[room.id] || "Chưa thuê"}
                        </p>
                      </td>
                      {Array.from({ length: 12 }, (_, index) => {
                        const periodKey = monthKey(year, index + 1);
                        const meters = metersByRoom[room.id] || [];
                        const electric = sumUsage(data.periods, meters, periodKey, "electric");
                        const water = sumUsage(data.periods, meters, periodKey, "water");
                        return (
                          <td
                            key={periodKey}
                            className={`p-2 text-center text-xs ${periodKey === period.key ? "bg-teal-50" : ""}`}
                          >
                            {electric !== null || water !== null ? (
                              <>
                                <span className="block text-amber-700">
                                  {electric === null ? "—" : `${display(electric)} kWh`}
                                </span>
                                <span className="block text-sky-700">
                                  {water === null ? "—" : `${display(water)} m³`}
                                </span>
                              </>
                            ) : (
                              <span className="text-slate-400">—</span>
                            )}
                          </td>
                        );
                      })}
                      <td className="bg-white p-2 text-center">
                        <button
                          type="button"
                          disabled={!writable(room)}
                          onClick={() => openRoom(room)}
                          title={room.room_type === "DORMITORY" ? "Công tơ chung KTX dùng luồng KTX" : undefined}
                          className="inline-flex min-h-10 items-center gap-1 whitespace-nowrap rounded-lg bg-teal-50 px-3 font-semibold text-teal-800 hover:bg-teal-100 disabled:opacity-50"
                        >
                          <Pencil size={16} />
                          {room.room_type === "DORMITORY" ? "Công tơ KTX" : "Ghi chỉ số"}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        ) : (
          <UtilityLedgerHistory rooms={rooms} room={selectedRoom} year={year} data={data}
            meters={selectedRoom ? (metersByRoom[selectedRoom.id] || []).filter(meter => meterKind(meter) !== "other") : []}
            selectRoom={id => {
              const room = rooms.find(item => item.id === id);
              setSelectedRoom(room ? { ...room, tenant: contracts[id] } : null);
            }}
            canWrite={Boolean(selectedRoom && writable(selectedRoom))}
            onRecord={() => openRoom(selectedRoom)} onSaved={saved} />
        )}
      </section>
      {dialogRoom && (
        <ReadingDialog
          key={dialogRoom.id}
          room={dialogRoom}
          meters={(metersByRoom[dialogRoom.id] || []).filter(meter => meterKind(meter) !== "other")}
          period={period}
          onClose={() => setDialogRoom(null)}
          onSaved={saved}
          onNext={nextRoom ? () => openRoom(nextRoom) : undefined}
          onPrevious={previousRoom ? () => openRoom(previousRoom) : undefined}
          position={position + 1} total={queue.length}
        />
      )}
      {bulk && (
        <BulkDialog
          rooms={rooms.filter(writable)}
          metersByRoom={metersByRoom}
          statusFor={statusFor} contracts={contracts}
          period={period}
          onClose={() => setBulk(false)}
          onSaved={saved}
        />
      )}
    </>
  );
}
