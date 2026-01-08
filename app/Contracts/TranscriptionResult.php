<?php

namespace App\Contracts;

final class TranscriptionResult
{
    public function __construct(
        public readonly string $transcript_text,
        public readonly int $duration_seconds,
        public readonly string $provider_name,
    ) {
    }

    /**
     * @return array{transcript_text:string,duration_seconds:int,provider_name:string}
     */
    public function toArray(): array
    {
        return [
            'transcript_text' => $this->transcript_text,
            'duration_seconds' => $this->duration_seconds,
            'provider_name' => $this->provider_name,
        ];
    }
}
