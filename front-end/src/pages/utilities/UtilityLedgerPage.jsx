import { Fragment, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "react-router-dom";
import {
  ChevronLeft,
  ChevronRight,
  Pencil,
  Search,
  X,
  Zap,
  Droplet,
  Gauge,
} from "lucide-react";
import { getBoardingHouses } from "../../api/boardingHouseApi";
import { getUtilityLedger } from "../../api/utilityLedgerApi";
import { confirmBillingPeriodBoundaries } from "../../api/billingV2Api";
import { getApiErrorMessage } from "../../api/getApiErrorMessage";
import UtilityLedgerHistory from "./UtilityLedgerHistory";
import { display, formatTime, readingError, usageStatus } from "./utilityLedgerDisplay.mjs";
import "./utilityLedger.css";

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
          className="rounded-xl border border-slate-200 bg-slate-50 p-4"
        >
          <p className={`flex items-center gap-2 font-semibold ${meterKind(meter) === "electric" ? "ledger-electric" : "ledger-water"}`}>
            {meterKind(meter) === "electric" ? <Zap size={16} aria-hidden="true" /> : <Droplet size={16} aria-hidden="true" />}
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
              className="mt-1 min-h-12 w-full rounded-lg border border-slate-200 bg-white px-3 text-xl font-semibold tabular-nums"
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
      className="ledger-dialog fixed inset-0 z-50 grid place-items-center bg-slate-950/35 p-3"
    >
      <div className="max-h-[calc(100dvh-24px)] w-full max-w-xl overflow-y-auto rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-xs font-medium text-teal-700">Phòng {position}/{total} · Kỳ {period.label}</p>
            <h2 id="reading-title" className="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
              Ghi chỉ số · {room.room_code}
            </h2>
            <p className="mt-1 text-sm text-slate-600">
              {room.tenant || "Chưa có người thuê"}
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
        {mutation.isSuccess && <p role="status" className="mt-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">Đã lưu chỉ số cho {room.room_code}.</p>}
        <div className="mt-4 flex items-center justify-between border-t border-slate-200 pt-2 text-sm text-slate-600">
          <button type="button" disabled={!onPrevious || mutation.isPending} onClick={onPrevious} className="min-h-11 rounded-lg px-2 hover:bg-slate-50 disabled:opacity-40">Phòng trước</button>
          {onNext && <button type="button" disabled={mutation.isPending} onClick={onNext} className="min-h-11 rounded-lg px-2 hover:bg-slate-50">Bỏ qua phòng</button>}
        </div>
        <div className="mt-2 flex gap-2 sm:justify-end">
          <button
            type="button"
            disabled={mutation.isPending || invalid}
            onClick={() => mutation.mutate()}
            className="min-h-11 flex-1 rounded-lg border border-teal-700 px-3 text-sm font-semibold text-teal-800 disabled:opacity-60 sm:flex-none"
          >
            {mutation.isPending ? "Đang lưu…" : "Lưu chỉ số"}
          </button>
          {onNext && <button type="button" disabled={mutation.isPending || invalid}
            onClick={() => mutation.mutate(true)}
            className="min-h-11 flex-1 rounded-lg bg-teal-700 px-3 text-sm font-semibold text-white sm:flex-none">
            Lưu & sang phòng tiếp theo
          </button>}
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
  const [expanded, setExpanded] = useState({});
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
      className="ledger-dialog fixed inset-0 z-50 bg-slate-950/35 p-0 sm:grid sm:place-items-center sm:p-4"
    >
      <div className="flex h-dvh min-w-0 w-full flex-col bg-white sm:h-auto sm:max-h-[calc(100dvh-32px)] sm:max-w-6xl sm:rounded-xl sm:border sm:border-slate-200">
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
        <div className="min-h-0 flex-1 overflow-auto bg-slate-50 p-3 sm:p-4">
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
          <div className="ledger-bulk-scroll">
            <table className="ledger-bulk-table" aria-label="Bảng ghi chỉ số hàng loạt">
              <colgroup><col className="ledger-check-col" /><col className="ledger-room-col" /></colgroup>
              <colgroup span="3" className="ledger-meter-cols" />
              <colgroup span="3" className="ledger-meter-cols" />
              <colgroup><col className="ledger-state-col" /></colgroup>
              <thead>
                <tr>
                  <th rowSpan={2}><input aria-label="Chọn tất cả" type="checkbox"
                    checked={selected.size === rooms.length}
                    onChange={event => setSelected(event.target.checked ? new Set(rooms.map(room => room.id)) : new Set())} /></th>
                  <th rowSpan={2} scope="col">Phòng</th>
                  <th colSpan={3} scope="colgroup" className="ledger-electric">Điện · kWh</th>
                  <th colSpan={3} scope="colgroup" className="ledger-water">Nước · m³</th>
                  <th rowSpan={2} scope="col">Trạng thái</th>
                </tr>
                <tr>{["electric", "water"].flatMap(kind => ["Cũ", "Mới", "Tiêu thụ"].map(label =>
                  <th scope="col" className="ledger-number" key={kind + label}>{label}</th>))}</tr>
              </thead>
              {shown.map(room => {
                const list = metersByRoom[room.id] || [];
                const electric = list.filter(meter => meterKind(meter) === "electric");
                const water = list.filter(meter => meterKind(meter) === "water");
                const meters = [...electric, ...water];
                const savedCount = meters.filter(meter => outcome[meter.id] === "Đã lưu").length;
                const failed = meters.some(meter => outcome[meter.id] && outcome[meter.id] !== "Đã lưu");
                const state = failed ? "Có mục lỗi" : savedCount === meters.length ? "Đã lưu" : savedCount ? "Lưu một phần" : "Chưa lưu";
                const cells = (meter, kind) => {
                  const value = meter && values[meter.id];
                  const invalid = meter && readingError(value, meter.previous);
                  const label = kind === "electric" ? "Điện" : "Nước";
                  return <>
                    <td data-kind={kind} data-field="old"><span className="ledger-mobile-label">{label} cũ</span>{meter ? display(meter.previous) : "—"}</td>
                    <td data-kind={kind} data-field="new"><span className="ledger-mobile-label">{label} mới</span>{meter ? <input
                      aria-label={`Chỉ số mới ${room.room_code} ${meter.service?.name || ""}`}
                      type="number" inputMode="decimal" aria-invalid={Boolean(invalid)}
                      value={value ?? ""} disabled={busy || outcome[meter.id] === "Đã lưu"}
                      onChange={event => setValues(current => ({ ...current, [meter.id]: event.target.value }))} /> : "—"}</td>
                    <td data-kind={kind} data-field="usage"><span className="ledger-mobile-label">Tiêu thụ</span>
                      {value == null || value === "" || invalid ? "—" : display(Number(value) - Number(meter.previous))}</td>
                  </>;
                };
                const changeSelection = event => {
                  const checked = event.target.checked;
                  setSelected(current => {
                    const next = new Set(current);
                    if (checked) next.add(room.id);
                    else next.delete(room.id);
                    return next;
                  });
                };
                return <tbody key={room.id}>
                  {Array.from({ length: Math.max(1, electric.length, water.length) }, (_, index) =>
                    <tr className="ledger-meter-row" key={index}>
                      <td data-field="check">{index === 0 && <label className="ledger-check-target"><input
                        type="checkbox" aria-label={`Chọn ${room.room_code}`} checked={selected.has(room.id)} onChange={changeSelection} /></label>}</td>
                      <th scope="row" data-field="room">{index === 0 ? <>{room.room_code}<small>{contracts[room.id] || "Chưa thuê"}</small></> : `Công tơ ${index + 1}`}</th>
                      {cells(electric[index], "electric")}{cells(water[index], "water")}
                      <td data-field="state">{index === 0 && <div className="flex items-center gap-1">
                        <span className="ledger-badge" data-status={state}>{state}</span>
                        <button type="button" className="ledger-expand" aria-label={`Giờ ghi ${room.room_code}`}
                          aria-expanded={Boolean(expanded[room.id])} onClick={() => setExpanded(current => ({ ...current, [room.id]: !current[room.id] }))}>
                          <ChevronRight size={16} aria-hidden="true" />
                        </button>
                      </div>}</td>
                    </tr>)}
                  {(expanded[room.id] || meters.some(meter => readingError(values[meter.id], meter.previous) || outcome[meter.id])) && <tr className="ledger-detail-row"><td colSpan={9}>
                    {meters.map(meter => {
                      const invalid = readingError(values[meter.id], meter.previous);
                      const result = outcome[meter.id];
                      return <Fragment key={meter.id}>
                        {invalid && <p role="alert" className="text-red-700">{meter.service?.name}: {invalid}</p>}
                        {result && result !== invalid && <p className={result === "Đã lưu" ? "text-teal-800" : "text-red-700"}>{meter.meter_code || meter.service?.name}: {result}</p>}
                      </Fragment>;
                    })}
                    {expanded[room.id] && <>
                      <div className="flex flex-wrap gap-3 pb-2">{meters.map(meter => <label key={meter.id}>
                        {meter.meter_code || meter.service?.name}
                        <input className="block min-h-11 rounded-lg border border-slate-200 px-2" type="datetime-local"
                          aria-label={`Giờ ghi ${room.room_code} ${meter.service?.name || ""}`}
                          value={meterDates[meter.id] ?? observedAt} disabled={busy || outcome[meter.id] === "Đã lưu"}
                          onChange={event => setMeterDates(current => ({ ...current, [meter.id]: event.target.value }))} />
                      </label>)}</div>
                    </>}
                  </td></tr>}
                </tbody>;
              })}
            </table>
          </div>
        </div>
        <footer className="grid shrink-0 grid-cols-2 gap-2 border-t border-slate-200 bg-white p-3 pb-[max(12px,env(safe-area-inset-bottom))] sm:flex sm:items-center sm:justify-end sm:px-4">
          <p className="col-span-2 text-xs text-slate-600 sm:mr-auto">{completed} đã lưu · {Object.keys(outcome).length - completed} mục cần kiểm tra</p>
          <button
            type="button"
            onClick={onClose}
            className="hidden min-h-11 rounded-lg px-4 font-semibold sm:inline-block"
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
      <section className="ledger-page mx-auto max-w-[1500px] space-y-4">
        <header className="flex items-center gap-3 pb-1">
          <span className="grid size-11 shrink-0 place-items-center rounded-lg border border-teal-100 bg-teal-50 text-teal-700"><Gauge size={22} aria-hidden="true" /></span>
          <div><h1 className="text-2xl font-semibold tracking-tight">Sổ điện nước</h1>
            <p className="text-sm text-slate-500">Ghi chỉ số và theo dõi lịch sử theo kỳ hóa đơn.</p></div>
        </header>
        <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3">
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
              <table className="ledger-annual-table w-full border-collapse text-sm tabular-nums">
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
