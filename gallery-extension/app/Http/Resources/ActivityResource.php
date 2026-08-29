<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
	public function toArray($request): array
	{
		return is_array($this->resource) ? $this->resource : (array) $this->resource;
	}
}
