<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AlbumCursorRequest extends FormRequest
{
	public function authorize(): bool
	{
		return $this->user() !== null;
	}

	public function rules(): array
	{
		return [
			'album_id' => ['required', 'regex:/^[A-Za-z0-9_-]{4,100}$/'],
			'cursor' => ['nullable', 'string', 'max:128'],
			'limit' => ['nullable', 'integer', 'min:1', 'max:60'],
		];
	}
}
