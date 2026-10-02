<?php

namespace App\Modules\MasterData\Http;

use App\Modules\MasterData\Application\RecordExchangeRate;
use Illuminate\Foundation\Http\FormRequest;

class RecordExchangeRateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return RecordExchangeRate::rules();
    }
}
