<?php

namespace App\Livewire;

use App\Models\TeamSkill;
use Laravel\Ai\Skills\Skill;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Skills')]
class SkillList extends Component
{
    public function render()
    {
        $fileSkills = collect(glob(resource_path('skills/*/SKILL.md')) ?: [])
            ->map(fn (string $file) => Skill::fromDirectory(dirname($file)))
            ->filter();

        return view('livewire.skill-list', [
            'fileSkills' => $fileSkills,
            'teamSkills' => TeamSkill::orderBy('name')->get(),
        ]);
    }
}
