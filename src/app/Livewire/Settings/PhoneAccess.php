<?php

namespace App\Livewire\Settings;

use App\Support\NetworkAddresses;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Accès téléphone')]
class PhoneAccess extends Component
{
    /** Adresses calculées au premier affichage (les requêtes Livewire suivantes ne passent pas par Apache de la même façon). */
    #[Locked]
    public array $addresses = [];

    #[Locked]
    public ?string $clientIp = null;

    #[Locked]
    public string $port = '';

    #[Locked]
    public bool $fromLan = false;

    public function mount(NetworkAddresses $network): void
    {
        $request = request();
        $this->addresses = $network->localIpv4($request->server('SERVER_ADDR'));
        $this->clientIp = $request->ip();
        $this->fromLan = $network->isLan($this->clientIp);
        $port = (int) $request->getPort();
        $this->port = in_array($port, [80, 443, 0], true) ? '' : ':'.$port;
    }

    public function render()
    {
        $ip = $this->addresses[0] ?? '192.168.1.20';

        return view('livewire.settings.phone-access', [
            'urls' => array_map(fn (string $address) => "http://{$address}{$this->port}", $this->addresses),
            'exampleIp' => $ip,
            'ipPrefix' => implode('.', array_slice(explode('.', $ip), 0, 3)),
            'publicPath' => str_replace('\\', '/', public_path()),
            'hostName' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'bouffe.local',
        ]);
    }
}
