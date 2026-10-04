<?php

namespace App\Services\Backup;

/**
 * Découpe un script SQL en instructions, sans se tromper sur les « ; » présents dans les textes
 * (étapes de recettes, notes…). Gère les chaînes '…' "…" `…`, les guillemets doublés,
 * l'échappement par antislash (MySQL / MariaDB) et les commentaires « -- ».
 */
class SqlStatementSplitter
{
    /** @return \Generator<int, string> */
    public function split(string $sql, bool $backslashEscapes = true): \Generator
    {
        $length = strlen($sql);
        $start = 0;
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($backslashEscapes && $char === '\\' && $quote !== '`') {
                    $i++;
                } elseif ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $i++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '-' && ($sql[$i + 1] ?? '') === '-' && $this->atLineStart($sql, $i, $start)) {
                // commentaire jusqu'à la fin de la ligne
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $sql = substr_replace($sql, str_repeat(' ', $end - $i), $i, $end - $i);
                $i = $end;
            } elseif ($char === ';') {
                $statement = trim(substr($sql, $start, $i - $start));
                $start = $i + 1;

                if ($statement !== '') {
                    yield $statement;
                }
            }
        }

        $rest = trim(substr($sql, $start));

        if ($rest !== '') {
            yield $rest;
        }
    }

    private function atLineStart(string $sql, int $position, int $statementStart): bool
    {
        $before = substr($sql, $statementStart, $position - $statementStart);
        $lastLine = strrchr("\n".$before, "\n");

        return trim($lastLine) === '';
    }
}
