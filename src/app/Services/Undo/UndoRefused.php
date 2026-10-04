<?php

namespace App\Services\Undo;

use RuntimeException;

/** L'annulation n'est plus possible : délai passé, ou quelqu'un a changé ces éléments entre-temps (R32). */
class UndoRefused extends RuntimeException {}
