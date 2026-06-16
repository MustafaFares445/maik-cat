<?php

namespace App\Services\Ecotrade;

use RuntimeException;

class EcotradeGeminiImageUnavailableException extends RuntimeException
{
    public function __construct(private readonly ?string $modelResponse = null)
    {
        $suffix = $modelResponse !== null ? ' Model response: '.$modelResponse : '';

        parent::__construct('Gemini image edit response did not contain image bytes.'.$suffix);
    }

    public function modelResponse(): ?string
    {
        return $this->modelResponse;
    }
}
