{{-- En-tête commun aux deux layouts : méta, icônes, écran d'accueil du téléphone --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#c0401c">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">

{{-- Thème (lot 11) : appliqué avant l'affichage pour éviter un flash clair. Choix enregistré sur l'appareil.
     « @nonce » : autorisé par la politique de contenu (lot 25, 27.7). --}}
<script @nonce>
    window.bouffeTheme = {
        get() { try { return localStorage.getItem('bouffe-theme') || 'auto'; } catch (e) { return 'auto'; } },
        set(theme) { try { localStorage.setItem('bouffe-theme', theme); } catch (e) {} this.apply(); },
        apply() {
            const theme = this.get();
            const dark = theme === 'dark' || (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            document.querySelector('meta[name=theme-color]')?.setAttribute('content', dark ? '#211b18' : '#c0401c');
        },
    };
    window.bouffeTheme.apply();
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => window.bouffeTheme.apply());
    document.addEventListener('livewire:navigated', () => window.bouffeTheme.apply());

    // Pages sans Alpine (impression, hors ligne) : pas d'attribut onclick (interdit par la politique de contenu).
    if (! window.bouffeActions) {
        window.bouffeActions = true;
        document.addEventListener('click', (event) => {
            const action = event.target.closest('[data-action]')?.dataset.action;
            if (action === 'print') window.print();
            if (action === 'reload') window.location.reload();
        });
        document.addEventListener('change', (event) => {
            if (event.target.matches('[data-submit-on-change]')) event.target.form?.submit();
        });
    }
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
