{{-- Récapitulatif de la semaine (19.3). Styles en ligne : les messageries ignorent les feuilles de style. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>La semaine du {{ $weekStart->locale('fr')->isoFormat('D MMMM') }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#292524;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f5f4;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;">
    <tr><td style="padding:24px 24px 8px;">
        <p style="margin:0;color:#c0401c;font-weight:700;font-size:14px;">Bouffe</p>
        <h1 style="margin:4px 0 0;font-size:22px;">La semaine du {{ $weekStart->locale('fr')->isoFormat('D MMMM') }}</h1>
        <p style="margin:6px 0 0;color:#78716c;font-size:14px;">Bonsoir {{ $user->name }}, voici ce qui est prévu.</p>
    </td></tr>

    @if ($receptions->isNotEmpty())
        <tr><td style="padding:16px 24px 0;">
            <h2 style="margin:0 0 6px;font-size:16px;color:#6d28d9;">Réceptions</h2>
            @foreach ($receptions as $occasion)
                <p style="margin:0 0 4px;font-size:14px;">
                    <strong>{{ $receptionService->name($occasion) }}</strong> —
                    {{ $occasion->serveAt()->locale('fr')->isoFormat('dddd D MMMM') }}{{ $occasion->serve_time ? ' à '.str_replace(':', ' h ', $occasion->serve_time) : '' }}
                </p>
            @endforeach
        </td></tr>
    @endif

    <tr><td style="padding:16px 24px 0;">
        <h2 style="margin:0 0 6px;font-size:16px;">Au menu</h2>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;">
            @foreach ($days as $day)
                @php $dayMeals = $meals->get($day->toDateString(), collect()); @endphp
                <tr>
                    <td valign="top" style="padding:6px 8px 6px 0;width:90px;color:#78716c;border-top:1px solid #e7e5e4;">{{ ucfirst($day->locale('fr')->isoFormat('ddd D')) }}</td>
                    <td valign="top" style="padding:6px 0;border-top:1px solid #e7e5e4;">
                        @forelse ($dayMeals as $meal)
                            <div>{{ $meal->slot?->name }} : {{ $meal->label() }}</div>
                        @empty
                            <span style="color:#a8a29e;">—</span>
                        @endforelse
                    </td>
                </tr>
            @endforeach
        </table>
    </td></tr>

    <tr><td style="padding:16px 24px 0;">
        <h2 style="margin:0 0 6px;font-size:16px;">Liste de courses</h2>
        @if (! $list)
            <p style="margin:0;font-size:14px;color:#78716c;">Pas encore de liste pour cette semaine.</p>
        @elseif ($items === [])
            <p style="margin:0;font-size:14px;color:#78716c;">Tout est coché. 👍</p>
        @else
            @foreach ($items as $aisle => $lines)
                <p style="margin:8px 0 2px;font-size:12px;font-weight:700;color:#c0401c;text-transform:uppercase;letter-spacing:.05em;">{{ $aisle }}</p>
                <p style="margin:0;font-size:14px;line-height:1.5;">{{ implode(' · ', $lines) }}</p>
            @endforeach
        @endif
    </td></tr>

    @if ($expiring->isNotEmpty())
        <tr><td style="padding:16px 24px 0;">
            <h2 style="margin:0 0 6px;font-size:16px;">À consommer rapidement</h2>
            <p style="margin:0;font-size:14px;line-height:1.5;">
                {{ $expiring->map(fn ($row) => $row['item']->name().' ('.$row['date']->locale('fr')->isoFormat('ddd D').')')->join(' · ') }}
            </p>
        </td></tr>
    @endif

    <tr><td style="padding:20px 24px 24px;">
        <a href="{{ route('planner.week', ['semaine' => $weekStart->toDateString()]) }}" style="display:inline-block;background:#c0401c;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600;font-size:14px;">Ouvrir le planning</a>
        <p style="margin:16px 0 0;font-size:12px;color:#a8a29e;">Vous recevez ce message car vous l'avez demandé dans Bouffe (Paramètres → Notifications).</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
