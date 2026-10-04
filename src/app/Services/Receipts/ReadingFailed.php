<?php

namespace App\Services\Receipts;

use RuntimeException;

/** Lecture impossible : le message est montré tel quel (en français), la saisie manuelle reste possible. */
class ReadingFailed extends RuntimeException {}
