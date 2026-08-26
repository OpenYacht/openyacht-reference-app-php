<?php

namespace App\Http\Requests;

use App\Models\CharterYacht;

class UpdateCharterYachtRequest extends StoreCharterYachtRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $yacht = $this->route('charterYacht');

        assert($yacht instanceof CharterYacht);

        return $this->user()->can('update', $yacht);
    }
}
