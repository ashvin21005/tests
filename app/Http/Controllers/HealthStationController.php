<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\HealthStation;
use App\Models\VaccinationSchedule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HealthStationController extends Controller
{
    public function index(Request $request)
    {
        $query = HealthStation::withCount([
            'children',
            'users as bhw_count' => fn ($users) => $users->where('role', 'BHW'),
        ])->orderBy('name');
        $search = trim((string) $request->query('search', ''));
        $barangay = $request->query('barangay');
        $status = $request->query('status');
        if ($search !== '') {
            $query->where(fn ($stations) => $stations->where('name', 'like', "%{$search}%")
                ->orWhere('barangay', 'like', "%{$search}%"));
        }
        if ($barangay) {
            $query->where('barangay', $barangay);
        }
        if (in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            $query->where('status', $status);
        }
        $stations = $query->paginate(15)->withQueryString();
        foreach ($stations as $station) {
            $counts = $this->scheduleCounts($station);
            $station->due_count = $counts->get('DUE', 0);
            $station->overdue_count = $counts->get('OVERDUE', 0);
            $station->low_stock_count = $station->vaccineInventory()->whereColumn('quantity', '<=', 'reorder_level')->count();
        }

        return view('cho.stations.index', [
            'stations' => $stations,
            'search' => $search,
            'barangays' => HealthStation::select('barangay')->distinct()->orderBy('barangay')->pluck('barangay'),
            'barangay' => $barangay,
            'status' => $status,
        ]);
    }

    public function create()
    {
        return view('cho.stations.form', [
            'station' => new HealthStation,
            'barangays' => Barangay::query()->orderBy('official_name')->get(['official_name', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:health_stations,name'],
            'barangay' => ['required', 'string', 'exists:barangays,official_name'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        $station = HealthStation::create($data);

        return redirect()->route('cho.stations.show', $station)->with('success', 'Health station created.');
    }

    public function destroy(HealthStation $station)
    {
        if ($station->users()->exists() || $station->children()->exists() || $station->vaccineInventory()->exists()) {
            return redirect()->route('cho.stations.index')
                ->with('error', 'This health station still has assigned BHWs, children, or inventory. Reassign or remove those records before deleting the station.');
        }

        $station->delete();

        return redirect()->route('cho.stations.index')->with('success', 'Health station deleted.');
    }

    public function show(HealthStation $station)
    {
        $station->load(['users' => fn ($users) => $users->where('role', 'BHW')->orderBy('name'), 'vaccineInventory'])
            ->loadCount('children');
        $counts = $this->scheduleCounts($station);
        $station->due_count = $counts->get('DUE', 0);
        $station->overdue_count = $counts->get('OVERDUE', 0);

        return view('cho.stations.show', compact('station'));
    }

    private function scheduleCounts(HealthStation $station)
    {
        return VaccinationSchedule::with('vaccineDefinition')
            ->whereHas('child', fn ($children) => $children->where('health_station_id', $station->id))
            ->get()
            ->countBy(fn ($schedule) => $schedule->display_status);
    }
}
