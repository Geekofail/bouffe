<?php

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Services\Together\Contributions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Qui apporte quoi » par lien (lot 42, 42.2) : pour ceux qui n'ont pas Bouffe.
 *
 * La page montre le nom de l'évènement, sa date et la liste « à apporter » avec les prénoms
 * inscrits — ni menu, ni allergies, ni participants, ni adresse. On s'y inscrit avec son prénom ;
 * une clé gardée dans le navigateur permet de se raviser (et seulement pour ses propres lignes).
 */
class BringController extends Controller
{
    private const KEY = 'bouffe_apporter';

    private const NAME = 'bouffe_apporter_nom';

    public function show(Request $request, string $token, Contributions $contributions): Response
    {
        $found = $contributions->findLink($token);

        if (! $found) {
            return response()->view('share.expired', [], 404)->header('X-Robots-Tag', 'noindex, nofollow');
        }

        ['link' => $link, 'subject' => $subject] = $found;
        $contributions->recordView($link);
        $rows = $contributions->of($subject);
        $key = (string) $request->cookie(self::KEY);

        return response()->view('share.bring', [
            'token' => $token,
            'title' => $contributions->title($subject),
            'when' => $contributions->when($subject),
            'rows' => $rows,
            'duplicates' => $contributions->duplicates($rows),
            'mine' => $key !== '' ? Contributions::hash($key) : null,
            'name' => (string) $request->cookie(self::NAME),
            'past' => $contributions->lastDay($subject)->isPast(),
        ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $token, Contributions $contributions): RedirectResponse
    {
        $found = $contributions->findLink($token) ?? abort(404);
        ['subject' => $subject] = $found;

        $data = $request->validate([
            'action' => 'required|in:add,take,release',
            'name' => 'nullable|string|max:60',
            'label' => 'nullable|string|max:100',
            'id' => 'nullable|integer',
        ], [], ['name' => 'prénom', 'label' => 'quoi']);

        $key = (string) $request->cookie(self::KEY) ?: Str::random(40);
        $name = Str::squish((string) ($data['name'] ?? ''));
        $back = redirect()->route('bring.show', ['token' => $token])
            ->withCookie(cookie(self::KEY, $key, 60 * 24 * 180, httpOnly: true, sameSite: 'lax'));

        if ($name !== '') {
            $back->withCookie(cookie(self::NAME, $name, 60 * 24 * 180, httpOnly: true, sameSite: 'lax'));
        }

        try {
            if ($data['action'] !== 'release' && $contributions->lastDay($subject)->isPast()) {
                throw new InvalidArgumentException('Cet évènement est passé.');
            }

            match ($data['action']) {
                'add' => $contributions->add($subject, (string) ($data['label'] ?? ''), [
                    'by_label' => $name !== '' ? $name : throw new InvalidArgumentException('Indiquez votre prénom.'),
                    'token_hash' => Contributions::hash($key),
                    'by_user_id' => null,
                ]),
                'take' => $contributions->claim($contributions->find($subject, (int) ($data['id'] ?? 0)), $name, [
                    'token_hash' => Contributions::hash($key),
                    'by_user_id' => null,
                ]),
                'release' => $this->release($contributions->find($subject, (int) ($data['id'] ?? 0)), $key, $contributions),
            };
        } catch (InvalidArgumentException $e) {
            return $back->withInput()->with('bring_error', $e->getMessage());
        }

        $contributions->afterChange($subject);

        return $back->with('bring_status', $data['action'] === 'release' ? 'C\'est noté : vous ne l\'apportez plus.' : 'Merci, c\'est noté !');
    }

    /** Seulement ce qu'on a pris soi-même par ce navigateur. */
    private function release(Contribution $contribution, string $key, Contributions $contributions): void
    {
        if (! $contribution->token_hash || ! hash_equals($contribution->token_hash, Contributions::hash($key))) {
            throw new InvalidArgumentException('Seule la personne qui s\'est inscrite peut se raviser.');
        }

        // Une ligne ajoutée par cette personne disparaît ; une ligne demandée par l'hôte redevient libre.
        $contribution->created_by === null
            ? $contributions->remove($contribution)
            : $contributions->release($contribution);
    }
}
