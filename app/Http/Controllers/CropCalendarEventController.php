<?php

namespace App\Http\Controllers;

use App\Models\CropCalendarEvent;
use App\Models\CropCycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Événements calendaires libres du module Production Végétale.
 *
 * Ces événements complètent le calendrier cultural (dates semis/récolte des
 * CropCycles) avec des entrées libres : traitements, observations, tâches,
 * rappels, etc.
 */
class CropCalendarEventController extends Controller
{
    public function index()
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $events = CropCalendarEvent::with('cropCycle:id,crop_name,code')
            ->orderByDesc('event_date')
            ->paginate((int) setting('general.items_per_page', 20));

        return view('cultures.calendar-events.index', [
            'events' => $events,
            'types'  => CropCalendarEvent::TYPES,
        ]);
    }

    public function create()
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.calendar-events.create', [
            'types'      => CropCalendarEvent::TYPES,
            'cropCycles' => CropCycle::orderByDesc('planting_date')->get(['id', 'crop_name', 'code']),
        ]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'title'         => 'required|string|max:200',
            'event_type'    => 'required|in:' . implode(',', array_keys(CropCalendarEvent::TYPES)),
            'event_date'    => 'required|date',
            'end_date'      => 'nullable|date|after_or_equal:event_date',
            'notes'         => 'nullable|string|max:1000',
            'crop_cycle_id' => 'nullable|exists:crop_cycles,id',
            'color'         => 'nullable|string|max:20',
        ]);

        CropCalendarEvent::create($validated);

        return redirect()->route('cultures.dashboard', ['tab' => 'calendar'])
            ->with('success', 'Événement « ' . $validated['title'] . ' » ajouté au calendrier.');
    }

    public function edit(CropCalendarEvent $cropCalendarEvent)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.calendar-events.edit', [
            'event'      => $cropCalendarEvent,
            'types'      => CropCalendarEvent::TYPES,
            'cropCycles' => CropCycle::orderByDesc('planting_date')->get(['id', 'crop_name', 'code']),
        ]);
    }

    public function update(Request $request, CropCalendarEvent $cropCalendarEvent)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'title'         => 'required|string|max:200',
            'event_type'    => 'required|in:' . implode(',', array_keys(CropCalendarEvent::TYPES)),
            'event_date'    => 'required|date',
            'end_date'      => 'nullable|date|after_or_equal:event_date',
            'notes'         => 'nullable|string|max:1000',
            'crop_cycle_id' => 'nullable|exists:crop_cycles,id',
            'color'         => 'nullable|string|max:20',
        ]);

        $cropCalendarEvent->update($validated);

        return redirect()->route('cultures.dashboard', ['tab' => 'calendar'])
            ->with('success', 'Événement mis à jour.');
    }

    public function destroy(CropCalendarEvent $cropCalendarEvent)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $cropCalendarEvent->delete();

        return back()->with('success', 'Événement supprimé.');
    }
}
