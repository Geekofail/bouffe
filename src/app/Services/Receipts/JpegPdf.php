<?php

namespace App\Services\Receipts;

use InvalidArgumentException;

/**
 * PDF minimal : une page par photo JPEG, l'image intégrée telle quelle (filtre DCTDecode).
 * Sert à envoyer en une seule lecture un ticket photographié en plusieurs fois (24.1).
 */
final class JpegPdf
{
    /** @param  list<string>  $paths */
    public static function build(array $paths): string
    {
        $objects = [];   // numéro => contenu
        $pageRefs = [];
        $next = 3;       // 1 = catalogue, 2 = arbre des pages

        foreach ($paths as $path) {
            $data = (string) file_get_contents($path);
            $info = @getimagesizefromstring($data);

            if (! $info || $info[2] !== IMAGETYPE_JPEG) {
                throw new InvalidArgumentException('Photo illisible pour le PDF.');
            }

            [$width, $height] = $info;
            $colorSpace = ($info['channels'] ?? 3) === 1 ? '/DeviceGray' : (($info['channels'] ?? 3) === 4 ? '/DeviceCMYK' : '/DeviceRGB');
            $image = $next++;
            $content = $next++;
            $page = $next++;

            $objects[$image] = "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace {$colorSpace} /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($data)." >>\nstream\n{$data}\nendstream";
            $draw = "q {$width} 0 0 {$height} 0 0 cm /Im0 Do Q";
            $objects[$content] = '<< /Length '.strlen($draw)." >>\nstream\n{$draw}\nendstream";
            $objects[$page] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}] /Resources << /XObject << /Im0 {$image} 0 R >> >> /Contents {$content} 0 R >>";
            $pageRefs[] = "{$page} 0 R";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageRefs).'] /Count '.count($pageRefs).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
