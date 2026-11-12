<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->walk->title }} — Gemba walk report</title>
    <style>
        @page { margin: 28px 32px 48px 32px; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #27272a; margin: 0; }
        h2 { font-size: 13px; font-weight: bold; color: #0d9488; margin: 22px 0 8px; page-break-after: avoid; }
        table { border-collapse: collapse; }
        .badge { display: inline-block; color: #fff; font-size: 8px; padding: 1px 5px; border-radius: 3px; }
        .muted { color: #71717a; }
        .red { color: #dc2626; }
    </style>
</head>
<body>

@php
    $walk = $report->walk;
    $totals = $report->totals();

    $categoryHex = [
        'safety' => '#ef4444',
        'quality' => '#3b82f6',
        'five_s' => '#8b5cf6',
        'waste' => '#f59e0b',
        'process' => '#06b6d4',
        'other' => '#71717a',
    ];

    $severityHex = [
        'high' => '#ef4444',
        'medium' => '#f59e0b',
        'low' => '#22c55e',
    ];
@endphp

{{-- Report header --}}
<div style="background: #0d9488; color: #ffffff; padding: 24px 28px;">
    <div style="font-size: 9px; letter-spacing: 2px; text-transform: uppercase; color: #ccfbf1;">Gemba walk report</div>
    <div style="font-size: 22px; margin-top: 6px; font-weight: bold;">{{ $walk->title }}</div>
    <div style="font-size: 10px; margin-top: 10px; color: #f0fdfa;">
        {{ $walk->area }}
        &nbsp;|&nbsp; {{ $walk->walked_on->format('j M Y') }}
        &nbsp;|&nbsp; {{ $walk->user->name }}
        &nbsp;|&nbsp; {{ $walk->status->label() }}
        &nbsp;|&nbsp; Generated {{ now()->format('j M Y') }}
    </div>
</div>

<div style="padding: 4px 28px 28px;">

    {{-- KPI row --}}
    <table width="100%" cellspacing="0" cellpadding="0" style="margin-top: 18px;">
        <tr>
            <td width="25%" style="border: 1px solid #e4e4e7; padding: 12px 14px;">
                <div style="font-size: 22px; font-weight: bold; color: #18181b;">{{ $totals['findings'] }}</div>
                <div style="font-size: 9px; color: #71717a;">Findings</div>
            </td>
            <td width="25%" style="border: 1px solid #e4e4e7; padding: 12px 14px;">
                <div style="font-size: 22px; font-weight: bold; color: {{ $totals['high'] > 0 ? '#dc2626' : '#18181b' }};">{{ $totals['high'] }}</div>
                <div style="font-size: 9px; color: #71717a;">High severity</div>
            </td>
            <td width="25%" style="border: 1px solid #e4e4e7; padding: 12px 14px;">
                <div style="font-size: 22px; font-weight: bold; color: {{ $totals['open_actions'] > 0 ? '#d97706' : '#18181b' }};">{{ $totals['open_actions'] }}</div>
                <div style="font-size: 9px; color: #71717a;">Open actions</div>
            </td>
            <td width="25%" style="border: 1px solid #e4e4e7; padding: 12px 14px;">
                <div style="font-size: 22px; font-weight: bold; color: {{ $totals['overdue'] > 0 ? '#dc2626' : '#18181b' }};">{{ $totals['overdue'] }}</div>
                <div style="font-size: 9px; color: #71717a;">Overdue</div>
            </td>
        </tr>
    </table>

    {{-- Checklist results --}}
    @php($checklist = $report->checklistTotals())
    @if ($checklist['answered'] > 0)
        <div style="page-break-inside: avoid;">
            <h2>Checklist results &nbsp;<span style="color: #27272a;">{{ $checklist['score'] }}% passed</span> <span class="muted" style="font-size: 10px; font-weight: normal;">({{ $checklist['yes'] }} Yes, {{ $checklist['no'] }} No)</span></h2>
            <table width="100%" cellspacing="0" cellpadding="3">
                @foreach ($report->checklistByCategory() as $group)
                    <tr>
                        <td width="28%" style="font-size: 10px;">{{ $group['name'] }}</td>
                        <td width="56%">
                            <div style="width: 100%; height: 8px; background: #ef4444; border-radius: 4px;">
                                <div style="width: {{ $group['score'] }}%; height: 8px; background: #22c55e; border-radius: 4px;"></div>
                            </div>
                        </td>
                        <td width="16%" align="right" style="font-size: 10px;">{{ $group['yes'] }}/{{ $group['answered'] }} &middot; {{ $group['score'] }}%</td>
                    </tr>
                @endforeach
            </table>
        </div>

        @if ($report->checklistFailures()->isNotEmpty())
            <div style="page-break-inside: avoid;">
                <div style="font-size: 11px; font-weight: bold; margin: 12px 0 4px;">Needs attention</div>
                <table width="100%" cellspacing="0" cellpadding="4">
                    @foreach ($report->checklistFailures() as $failure)
                        @php($thumb = $failure->finding ? $report->thumbnailDataUri($failure->finding) : null)
                        <tr style="page-break-inside: avoid; border-bottom: 1px solid #f4f4f5;">
                            <td width="52" valign="top">
                                @if ($thumb)
                                    <img src="{{ $thumb }}" style="width: 48px; height: 36px; border-radius: 3px;">
                                @endif
                            </td>
                            <td valign="top" style="font-size: 10px;">
                                <span class="muted">{{ $failure->category_name }}:</span> {{ $failure->question }}
                                @if ($failure->note)
                                    <div class="muted" style="margin-top: 2px;">{{ $failure->note }}</div>
                                @endif
                            </td>
                            <td width="30" valign="top" align="right"><span class="badge" style="background: #ef4444;">No</span></td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    @endif

    @if ($report->findings->isNotEmpty())
        {{-- Category breakdown --}}
        <div style="page-break-inside: avoid;">
            <h2>Findings by category</h2>
            <table width="100%" cellspacing="0" cellpadding="4">
                @foreach ($report->byCategory() as $entry)
                    <tr>
                        <td style="width: 16%; font-size: 10px; vertical-align: middle;">{{ $entry['category']->label() }}</td>
                        <td style="width: 74%; vertical-align: middle;">
                            <div style="height: 8px; background: #f4f4f5; border-radius: 4px;">
                                <div style="height: 8px; width: {{ $entry['percent'] }}%; background: {{ $categoryHex[$entry['category']->value] }}; border-radius: 4px;"></div>
                            </div>
                        </td>
                        <td style="width: 10%; text-align: right; font-size: 10px; vertical-align: middle;">{{ $entry['count'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>

        {{-- Severity breakdown --}}
        <div style="page-break-inside: avoid;">
            <h2>Findings by severity</h2>
            <table width="100%" cellspacing="0" cellpadding="0">
                <tr>
                    @foreach ($report->bySeverity() as $entry)
                        @if ($entry['count'] > 0)
                            <td style="height: 12px; width: {{ $entry['percent'] }}%; background: {{ $severityHex[$entry['severity']->value] }};"></td>
                        @endif
                    @endforeach
                </tr>
            </table>
            <div style="margin-top: 8px; font-size: 10px;">
                @foreach ($report->bySeverity() as $entry)
                    <span style="margin-right: 18px;">
                        <span style="display: inline-block; width: 8px; height: 8px; background: {{ $severityHex[$entry['severity']->value] }}; border-radius: 50%;"></span>
                        {{ $entry['severity']->label() }} <strong>{{ $entry['count'] }}</strong>
                    </span>
                @endforeach
            </div>
        </div>

        {{-- Top priorities --}}
        @if ($report->priorities()->isNotEmpty())
            <div style="page-break-inside: avoid;">
                <h2>Top priorities</h2>
                <table width="100%" cellspacing="0" cellpadding="6">
                    @foreach ($report->priorities() as $finding)
                        <tr>
                            <td width="64" valign="top">
                                @php($uri = $report->thumbnailDataUri($finding))
                                @if ($uri)
                                    <img src="{{ $uri }}" style="width: 56px; height: 56px; object-fit: cover; border-radius: 4px;" />
                                @else
                                    <div style="width: 56px; height: 56px; background: #f4f4f5; color: #a1a1aa; text-align: center; line-height: 56px; border-radius: 4px; font-size: 8px;">No photo</div>
                                @endif
                            </td>
                            <td valign="top">
                                <div>
                                    <strong style="color: #a1a1aa;">#{{ $finding->position }}</strong>
                                    <span class="badge" style="background: {{ $categoryHex[$finding->category->value] }};">{{ $finding->category->label() }}</span>
                                    <span class="badge" style="background: {{ $severityHex[$finding->severity->value] }};">{{ $finding->severity->label() }}</span>
                                </div>
                                <div style="margin-top: 4px; font-size: 10px;">{{ $finding->observation }}</div>
                                <div class="muted" style="margin-top: 3px; font-size: 9px;">
                                    @if ($finding->action_owner)
                                        {{ $finding->action_owner }}
                                    @endif
                                    @if ($finding->action_owner && $finding->due_on)
                                        &nbsp;&middot;&nbsp;
                                    @endif
                                    @if ($finding->due_on)
                                        <span class="{{ $finding->isOverdue() ? 'red' : '' }}">{{ $finding->due_on->format('j M Y') }}</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif

        {{-- Takeaways --}}
        <div style="page-break-inside: avoid;">
            <h2>Takeaways</h2>
            @if ($walk->summary)
                <div style="font-size: 10px;">{{ $walk->summary }}</div>
            @else
                <div class="muted" style="font-size: 10px;">No summary recorded.</div>
            @endif
        </div>

        {{-- Photo evidence --}}
        <div style="page-break-inside: avoid;">
            <h2>Photo evidence</h2>
            <table width="100%" cellspacing="0" cellpadding="4">
                @foreach ($report->findings->chunk(3) as $chunk)
                    <tr>
                        @foreach ($chunk as $finding)
                            <td width="33%" valign="top">
                                @php($uri = $report->thumbnailDataUri($finding))
                                @if ($uri)
                                    <img src="{{ $uri }}" style="width: 100%; border-radius: 4px;" />
                                @else
                                    <div style="height: 72px; background: #f4f4f5; color: #a1a1aa; text-align: center; line-height: 72px; border-radius: 4px; font-size: 8px;">No photo</div>
                                @endif
                                <div style="margin-top: 4px;">
                                    <strong style="color: #a1a1aa; font-size: 9px;">#{{ $finding->position }}</strong>
                                    <span class="badge" style="background: {{ $categoryHex[$finding->category->value] }};">{{ $finding->category->label() }}</span>
                                </div>
                                @if ($finding->observation)
                                    <div style="margin-top: 3px; font-size: 9px;">{{ $finding->observation }}</div>
                                @endif
                            </td>
                        @endforeach

                        @for ($i = $chunk->count(); $i < 3; $i++)
                            <td width="33%"></td>
                        @endfor
                    </tr>
                @endforeach
            </table>
        </div>

        {{-- Action plan --}}
        <div style="page-break-inside: avoid;">
            <h2>Action plan</h2>
            @if ($report->actionPlan()->isNotEmpty())
                <table width="100%" cellspacing="0" cellpadding="6">
                    <tr style="background: #f4f4f5;">
                        <th style="width: 6%; text-align: left; font-size: 9px;">#</th>
                        <th style="width: 30%; text-align: left; font-size: 9px;">Finding</th>
                        <th style="width: 30%; text-align: left; font-size: 9px;">Action required</th>
                        <th style="width: 14%; text-align: left; font-size: 9px;">Owner</th>
                        <th style="width: 12%; text-align: left; font-size: 9px;">Due</th>
                        <th style="width: 8%; text-align: left; font-size: 9px;">Status</th>
                    </tr>
                    @foreach ($report->actionPlan() as $finding)
                        <tr>
                            <td style="font-size: 9px; border-bottom: 1px solid #f4f4f5;">#{{ $finding->position }}</td>
                            <td style="font-size: 9px; border-bottom: 1px solid #f4f4f5;">{{ $finding->observation }}</td>
                            <td style="font-size: 9px; border-bottom: 1px solid #f4f4f5;">{{ $finding->action_required }}</td>
                            <td style="font-size: 9px; border-bottom: 1px solid #f4f4f5;">{{ $finding->action_owner ?? '—' }}</td>
                            <td style="font-size: 9px; border-bottom: 1px solid #f4f4f5;">
                                @if ($finding->due_on)
                                    <span class="{{ $finding->isOverdue() ? 'red' : '' }}">
                                        {{ $finding->due_on->format('j M Y') }}
                                        @if ($finding->isOverdue())
                                            <strong>Overdue</strong>
                                        @endif
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td style="font-size: 9px; border-bottom: 1px solid #f4f4f5;">
                                <span class="badge" style="background: {{ $finding->action_status === \App\Enums\ActionStatus::Done ? '#22c55e' : '#f59e0b' }};">{{ $finding->action_status->label() }}</span>
                            </td>
                        </tr>
                    @endforeach
                </table>
            @else
                <div class="muted" style="font-size: 10px;">No actions were recorded.</div>
            @endif
        </div>
    @else
        <div class="muted" style="margin-top: 20px; font-size: 10px;">No findings were recorded on this walk.</div>
    @endif
</div>

</body>
</html>
