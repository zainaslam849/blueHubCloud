<?php

namespace App\Contracts;

use App\Models\CallRecording;

interface TranscriptionService
{
    /**
     * Transcribe a call recording.
     *
     * Implementations must use $callRecording->storage_path as the source.
     */
    public function transcribe(CallRecording $callRecording): TranscriptionResult;
}
