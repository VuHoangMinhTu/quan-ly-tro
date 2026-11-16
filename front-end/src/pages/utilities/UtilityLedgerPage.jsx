import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ChevronLeft,
  ChevronRight,
  Droplets,
  Pencil,
  Search,
  X,
  Zap,
} from "lucide-react";
import { getBoardingHouses } from "../../api/boardingHouseApi";
import { getUtilityLedger } from "../../api/utilityLedgerApi";
import { confirmBillingPeriodBoundaries } from "../../api/billingV2Api";
import { getApiErrorMessage } from "../../api/getApiErrorMessage";

const currentYear = new Date().getFullYear();
const localDateTime = () =>
  new Date()
    .toLocaleString("sv-SE", { timeZone: "Asia/Ho_Chi_Minh" })
    .replace(" ", "T")
    .slice(0, 16);
const apiTime = (value) => value.replace("T", " ") + ":00";
const monthKey = (year, month) =>
  `${year}-${String(month).padStart(2, "0")}-01`;
const meterKind = (meter) =>
  meter.service?.type === "ELECTRICITY"
    ? "electric"
    : meter.service?.type === "WATER"
      ? "water"
      : "other";
const display = (value) =>
  Number(value).toLocaleString("vi-VN", { maximumFractionDigits: 2 });

function usageFor(periods, meterId, period) {
  const row = periods.find(
    (item) =>
      Number(item.utility_meter_id) === Number(meterId) &&
      item.billing_period === period,
  );
  return row ? Number(row.quantity) : null;
}

function MeterInputs({ meters, initial, onChange }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {meters.map((meter) => (
        <section
          key={meter.id}
          className="rounded-xl border border-slate-200 p-3"
        >
          <p className="font-semibold text-slate-900">
            {meter.service?.name || "Công tơ"}{" "}
            <span className="text-xs font-normal text-slate-500">
              {meter.meter_code || `#${meter.id}`}
            </span>
          </p>
          <label className="mt-2 block text-sm font-medium text-slate-700">
            Chỉ số mới
            <input
              inputMode="decimal"
              type="number"
              min="0"
              step="0.01"
              value={initial[meter.id] ?? ""}
              onChange={(event) => onChange(meter.id, event.target.value)}
              className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"
            />
          </label>
        </section>
      ))}
    </div>
  );
}

function ReadingDialog({ room, meters, period, onClose, onSaved }) {
  const [readings, setReadings] = useState({});
  const [observedAt, setObservedAt] = useState(localDateTime());
  const [error, setError] = useState("");
  const mutation = useMutation({
    mutationFn: async () => {
      const results = [];
      for (const meter of meters) {
        const next = readings[meter.id];
        if (next === undefined || next === "") continue;
        const previous = meter.previous ?? meter.initial_reading ?? 0;
        results.push(
          confirmBillingPeriodBoundaries(meter.id, {
            start_observed_at: apiTime(period.start),
            end_observed_at: apiTime(observedAt),
            start_reading_value: previous,
            end_reading_value: next,
          }),
        );
      }
      if (!results.length)
        throw new Error("Nhập ít nhất một chỉ số mới trước khi lưu.");
      return Promise.all(results);
    },
    onSuccess: onSaved,
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
              {room.tenant || "Chưa có người thuê"} · Kỳ {period.label}
            </p>
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
            disabled={mutation.isPending}
            onClick={() => mutation.mutate()}
            className="min-h-11 rounded-lg bg-teal-700 px-5 font-semibold text-white disabled:opacity-60"
          >
            {mutation.isPending ? "Đang lưu…" : "Lưu chỉ số"}
          </button>
        </div>
      </div>
    </div>
  );
}

function BulkDialog({ rooms, metersByRoom, period, onClose, onSaved }) {
  const [values, setValues] = useState({});
  const [selected, setSelected] = useState(
    () => new Set(rooms.map((room) => room.id)),
  );
  const [outcome, setOutcome] = useState({});
  const save = async () => {
    const next = {};
    for (const room of rooms.filter((item) => selected.has(item.id))) {
      try {
        await Promise.all(
          (metersByRoom[room.id] || [])
            .filter(
              (meter) =>
                values[meter.id] !== undefined && values[meter.id] !== "",
            )
            .map((meter) =>
              confirmBillingPeriodBoundaries(meter.id, {
                start_observed_at: apiTime(period.start),
                end_observed_at: apiTime(localDateTime()),
                start_reading_value:
                  meter.previous ?? meter.initial_reading ?? 0,
                end_reading_value: values[meter.id],
              }),
            ),
        );
        next[room.id] = "Đã lưu";
      } catch {
        next[room.id] = "Lỗi – thử lại";
      }
    }
    setOutcome((current) => ({ ...current, ...next }));
    onSaved();
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
              Kỳ {period.label}; chỉ những ô hợp lệ được gửi.
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
          <table className="min-w-[760px] w-full text-sm">
            <thead className="sticky top-0 bg-white text-left text-slate-600">
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
                <th className="p-2">Điện cũ / mới / kWh</th>
                <th className="p-2">Nước cũ / mới / m³</th>
                <th className="p-2">Trạng thái</th>
              </tr>
            </thead>
            <tbody>
              {rooms.map((room) => {
                const list = metersByRoom[room.id] || [];
                const electric = list.find((m) => meterKind(m) === "electric");
                const water = list.find((m) => meterKind(m) === "water");
                const input = (meter) =>
                  meter ? (
                    <div className="flex items-center gap-1">
                      <span>
                        {display(meter.previous ?? meter.initial_reading ?? 0)}
                      </span>
                      <input
                        aria-label={`Chỉ số mới ${room.room_code}`}
                        inputMode="decimal"
                        type="number"
                        value={values[meter.id] ?? ""}
                        onChange={(event) =>
                          setValues((state) => ({
                            ...state,
                            [meter.id]: event.target.value,
                          }))
                        }
                        className="w-24 rounded border border-slate-300 px-2 py-2"
                      />
                      <span>
                        {values[meter.id] === undefined ||
                        values[meter.id] === ""
                          ? "—"
                          : display(
                              Number(values[meter.id]) -
                                Number(
                                  meter.previous ?? meter.initial_reading ?? 0,
                                ),
                            )}
                      </span>
                    </div>
                  ) : (
                    "—"
                  );
                return (
                  <tr key={room.id} className="border-b border-slate-100">
                    <td className="p-2">
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
                    <td className="p-2 font-semibold">{room.room_code}</td>
                    <td className="p-2">{input(electric)}</td>
                    <td className="p-2">{input(water)}</td>
                    <td className="p-2">{outcome[room.id] || "Chưa lưu"}</td>
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
            onClick={save}
            className="min-h-11 rounded-lg bg-teal-700 px-5 font-semibold text-white"
          >
            Lưu tất cả
          </button>
        </footer>
      </div>
    </div>
  );
}

export default function UtilityLedgerPage() {
  const [year, setYear] = useState(currentYear);
  const [recordMonth, setRecordMonth] = useState(
    () =>
      `${currentYear}-${String(new Date().getMonth() + 1).padStart(2, "0")}`,
  );
  const [house, setHouse] = useState("");
  const [tab, setTab] = useState("all");
  const [search, setSearch] = useState("");
  const [selectedRoom, setSelectedRoom] = useState(null);
  const [bulk, setBulk] = useState(false);
  const houses = useQuery({
    queryKey: ["boarding-houses"],
    queryFn: getBoardingHouses,
  });
  const houseId = house || houses.data?.data?.data?.[0]?.id || "";
  const ledger = useQuery({
    queryKey: ["utility-ledger", houseId, year],
    queryFn: () => getUtilityLedger(houseId, year),
    enabled: Boolean(houseId),
  });
  const client = useQueryClient();
  const data = ledger.data?.data?.data;
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
                point.observed_at < `${recordMonth}-01 00:00:00`,
            )
            .sort((a, b) => a.observed_at.localeCompare(b.observed_at))
            .at(-1);
          return {
            ...meter,
            previous: previous?.reading_value ?? meter.initial_reading,
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
  const visible = rooms.filter((room) =>
    room.room_code.toLowerCase().includes(search.toLowerCase()),
  );
  const period = {
    start: `${recordMonth}-01T00:00`,
    label: `tháng ${Number(recordMonth.slice(5))}/${recordMonth.slice(0, 4)}`,
  };
  const openRoom = (room) =>
    setSelectedRoom({ ...room, tenant: contracts[room.id] });
  const saved = () =>
    client.invalidateQueries({ queryKey: ["utility-ledger", houseId, year] });
  if (houses.isPending || ledger.isPending)
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
              value={houseId}
              onChange={(event) => setHouse(event.target.value)}
              className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3"
            >
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
              onChange={(event) => setRecordMonth(event.target.value)}
              className="mt-1 min-h-11 rounded-lg border border-slate-300 px-3"
            />
          </label>
          <button
            type="button"
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
        {tab === "all" ? (
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
                      <th key={index} className="min-w-20 p-2 text-center">
                        T{index + 1}
                      </th>
                    ))}
                    <th className="sticky right-0 z-10 bg-slate-50 p-3">
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
                            openRoom(room);
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
                        const electric = (metersByRoom[room.id] || [])
                          .filter((m) => meterKind(m) === "electric")
                          .reduce(
                            (sum, m) =>
                              sum +
                              (usageFor(data.periods, m.id, periodKey) ?? 0),
                            0,
                          );
                        const water = (metersByRoom[room.id] || [])
                          .filter((m) => meterKind(m) === "water")
                          .reduce(
                            (sum, m) =>
                              sum +
                              (usageFor(data.periods, m.id, periodKey) ?? 0),
                            0,
                          );
                        const has = (metersByRoom[room.id] || []).some(
                          (m) =>
                            usageFor(data.periods, m.id, periodKey) !== null,
                        );
                        return (
                          <td
                            key={periodKey}
                            className="p-2 text-center text-xs"
                          >
                            {has ? (
                              <>
                                <span className="block text-amber-700">
                                  {display(electric)} kWh
                                </span>
                                <span className="block text-sky-700">
                                  {display(water)} m³
                                </span>
                              </>
                            ) : (
                              <span className="text-slate-400">—</span>
                            )}
                          </td>
                        );
                      })}
                      <td className="sticky right-0 z-10 bg-white p-2 text-center">
                        <button
                          type="button"
                          onClick={() => openRoom(room)}
                          className="inline-flex min-h-10 items-center gap-1 rounded-lg bg-teal-50 px-3 font-semibold text-teal-800 hover:bg-teal-100"
                        >
                          <Pencil size={16} />
                          Ghi chỉ số
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        ) : (
          <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
            {selectedRoom ? (
              <>
                <div className="flex flex-wrap justify-between gap-3">
                  <div>
                    <h2 className="text-xl font-bold">
                      {selectedRoom.room_code}
                    </h2>
                    <p className="text-sm text-slate-600">
                      {selectedRoom.tenant || "Chưa có người thuê"}
                    </p>
                  </div>
                  <button
                    onClick={() => openRoom(selectedRoom)}
                    className="min-h-11 rounded-lg bg-teal-700 px-4 font-semibold text-white"
                  >
                    Ghi chỉ số
                  </button>
                </div>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  {Array.from({ length: 12 }, (_, index) => {
                    const month = monthKey(year, index + 1);
                    const roomMeters = metersByRoom[selectedRoom.id] || [];
                    const electricity = roomMeters
                      .filter((m) => meterKind(m) === "electric")
                      .reduce(
                        (sum, meter) =>
                          sum + (usageFor(data.periods, meter.id, month) ?? 0),
                        0,
                      );
                    const water = roomMeters
                      .filter((m) => meterKind(m) === "water")
                      .reduce(
                        (sum, meter) =>
                          sum + (usageFor(data.periods, meter.id, month) ?? 0),
                        0,
                      );
                    const recorded = roomMeters.some(
                      (meter) =>
                        usageFor(data.periods, meter.id, month) !== null,
                    );
                    return (
                      <article
                        key={index}
                        className="rounded-xl border border-slate-200 p-3"
                      >
                        <strong>Tháng {index + 1}</strong>
                        {recorded ? (
                          <>
                            <p className="mt-2 text-amber-700">
                              <Zap className="mr-1 inline" size={16} />
                              Điện: {display(electricity)} kWh
                            </p>
                            <p className="text-sky-700">
                              <Droplets className="mr-1 inline" size={16} />
                              Nước: {display(water)} m³
                            </p>
                          </>
                        ) : (
                          <p className="mt-2 text-sm text-slate-500">
                            Chưa ghi kỳ này
                          </p>
                        )}
                        <p className="mt-2 text-xs text-slate-500">
                          {recorded
                            ? "Đã liên kết kỳ hóa đơn"
                            : "Không hiển thị số 0 giả"}
                        </p>
                      </article>
                    );
                  })}
                </div>
              </>
            ) : (
              <p className="text-slate-600">
                Chọn một phòng trong tab Cả nhà để xem lịch sử 12 tháng.
              </p>
            )}
          </div>
        )}
      </section>
      {selectedRoom && (
        <ReadingDialog
          room={selectedRoom}
          meters={metersByRoom[selectedRoom.id] || []}
          period={period}
          onClose={() => setSelectedRoom(null)}
          onSaved={() => {
            saved();
            setSelectedRoom(null);
          }}
        />
      )}
      {bulk && (
        <BulkDialog
          rooms={visible}
          metersByRoom={metersByRoom}
          period={period}
          onClose={() => setBulk(false)}
          onSaved={saved}
        />
      )}
    </>
  );
}
