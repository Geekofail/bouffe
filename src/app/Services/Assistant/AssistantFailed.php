<?php

namespace App\Services\Assistant;

use RuntimeException;

/** L'assistant n'a pas pu répondre : le message est prêt à être montré tel quel. */
class AssistantFailed extends RuntimeException {}
