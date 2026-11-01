<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Skills\Skill;

/**
 * A skill stored in the database, loaded into the agent per team.
 */
#[Fillable(['team', 'name', 'description', 'instructions', 'enabled'])]
class TeamSkill extends Model
{
    protected $table = 'skills';

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function scopeEnabledFor(Builder $query, string $team): void
    {
        $query->where('team', $team)->where('enabled', true);
    }

    /**
     * Convert the row into an SDK skill.
     */
    public function toSkill(): Skill
    {
        return new Skill($this->name, $this->description, $this->instructions);
    }
}
