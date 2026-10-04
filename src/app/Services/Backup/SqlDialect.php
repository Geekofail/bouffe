<?php

namespace App\Services\Backup;

use Illuminate\Database\Connection;

/**
 * Restauration d'une sauvegarde MariaDB dans MySQL, et inversement (lot 25, 27.8).
 *
 * Les sauvegardes contiennent le « SHOW CREATE TABLE » du serveur d'origine. Quelques tournures
 * n'existent que d'un côté :
 *
 *  - MariaDB : `current_timestamp()`, type natif `uuid`, contraintes `CHECK (json_valid(…))`
 *    nommées d'après la colonne (MySQL exige des noms uniques dans toute la base), interclassements
 *    `utf8mb4_uca1400_*` (MariaDB 11) ;
 *  - MySQL : interclassements `utf8mb4_0900_*`, commentaires `/*!80016 DEFAULT ENCRYPTION='N' * /`
 *    que MariaDB exécuterait et refuserait.
 *
 * Chaque instruction CREATE TABLE est ramenée à une forme que les deux comprennent ; un
 * interclassement inconnu du serveur cible devient utf8mb4_unicode_ci (celui de Laravel).
 */
class SqlDialect
{
    /** @var array<string, true>|null */
    private ?array $collations = null;

    /**
     * @param  bool|null  $mariadb  serveur cible (null : demandé au serveur)
     * @param  list<string>|null  $collations  interclassements connus du serveur cible (null : demandés au serveur)
     */
    public function __construct(private readonly Connection $connection, private ?bool $mariadb = null, ?array $collations = null)
    {
        if ($collations !== null) {
            $this->collations = array_fill_keys(array_map('strtolower', $collations), true);
        }
    }

    public function adapt(string $statement): string
    {
        if (! preg_match('/^\s*CREATE\s+TABLE/i', $statement)) {
            return $statement;
        }

        // current_timestamp() → CURRENT_TIMESTAMP (les deux l'acceptent)
        $statement = preg_replace('/\bcurrent_timestamp\(\)/i', 'CURRENT_TIMESTAMP', $statement);

        // Contraintes JSON ajoutées par MariaDB (colonnes json) : inutiles, et en conflit de nom chez MySQL.
        $statement = preg_replace('/,\s*CONSTRAINT\s+`[^`]+`\s+CHECK\s*\(\s*json_valid\(`[^`]+`\)\s*\)/i', '', $statement);
        $statement = preg_replace('/\s+CHECK\s*\(\s*json_valid\(`[^`]+`\)\s*\)/i', '', $statement);

        // Commentaires conditionnels de MySQL 8 (chiffrement par défaut) : MariaDB les exécuterait.
        $statement = preg_replace('#\s*/\*!800\d\d[^*]*\*/#', '', $statement);

        // Type uuid natif de MariaDB : char(36) ailleurs (c'est ce que Laravel crée sous MySQL).
        if (! $this->isMariaDb()) {
            $statement = preg_replace('/(`[^`]+`\s+)uuid\b/i', '$1char(36)', $statement);
        }

        return preg_replace_callback('/\butf8mb4_[a-z0-9_]+\b/i', fn ($m) => $this->collationExists($m[0]) ? $m[0] : 'utf8mb4_unicode_ci', $statement);
    }

    public function isMariaDb(): bool
    {
        if ($this->mariadb === null) {
            try {
                $version = (string) $this->connection->selectOne('select version() as v')->v;
            } catch (\Throwable) {
                $version = (string) $this->connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            }

            $this->mariadb = str_contains(strtolower($version), 'mariadb');
        }

        return $this->mariadb;
    }

    private function collationExists(string $name): bool
    {
        if ($this->collations === null) {
            $this->collations = [];

            try {
                foreach ($this->connection->select("SHOW COLLATION WHERE Charset = 'utf8mb4'") as $row) {
                    $this->collations[strtolower((string) ((array) $row)['Collation'])] = true;
                }
            } catch (\Throwable) {
                return true;   // liste illisible : on ne touche à rien
            }
        }

        return isset($this->collations[strtolower($name)]);
    }
}
