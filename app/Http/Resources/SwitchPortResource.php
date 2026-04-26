<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SwitchPort;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SwitchPort
 */
class SwitchPortResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'interface' => $this->port_name,
            'description' => $this->switch_description,
            'status' => $this->status,
            'admin_status' => $this->admin_status,
            'speed' => $this->speed,
            'vlan' => $this->access_vlan,
            'poe' => $this->poe_status,
            'duplex' => $this->duplex,
            'switchport_mode' => $this->switchport_mode,
        ];
    }
}
