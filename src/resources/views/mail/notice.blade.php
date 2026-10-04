{{-- E-mail court (lot 25) : App\Mail\Notice. Styles en ligne : les messageries ignorent les feuilles de style. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#292524;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f5f4;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:12px;">
    <tr><td style="padding:24px 24px 8px;">
        <p style="margin:0;color:#c0401c;font-weight:700;font-size:14px;">Bouffe</p>
        <h1 style="margin:4px 0 0;font-size:22px;">{{ $title }}</h1>
    </td></tr>
    <tr><td style="padding:8px 24px 0;font-size:15px;line-height:1.5;">
        @foreach ($paragraphs as $paragraph)
            <p style="margin:0 0 12px;">{{ $paragraph }}</p>
        @endforeach
    </td></tr>
    @if ($action)
        <tr><td style="padding:8px 24px 16px;">
            <a href="{{ $action[1] }}" style="display:inline-block;background:#c0401c;color:#ffffff;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:8px;font-size:15px;">{{ $action[0] }}</a>
            <p style="margin:12px 0 0;font-size:12px;color:#78716c;word-break:break-all;">{{ $action[1] }}</p>
        </td></tr>
    @endif
    @if ($footer)
        <tr><td style="padding:0 24px 24px;font-size:13px;color:#57534e;border-top:1px solid #e7e5e4;">
            <p style="margin:12px 0 0;">{{ $footer }}</p>
        </td></tr>
    @endif
</table>
</td></tr>
</table>
</body>
</html>
