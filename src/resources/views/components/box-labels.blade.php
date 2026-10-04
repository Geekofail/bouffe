{{-- Planche d'étiquettes (deux par ligne) et dessin des QR codes, côté navigateur. --}}
<div {{ $attributes->merge(['class' => 'grid grid-cols-2 gap-3 print:gap-2']) }}>
    {{ $slot }}
</div>

@once
    <script @nonce>
        // Les QR codes sont dessinés dans le navigateur : rien à générer côté serveur.
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('canvas[data-qr]').forEach((canvas) => {
                window.bouffeQrCode?.(canvas, canvas.dataset.qr);
            });
        });
    </script>
@endonce
