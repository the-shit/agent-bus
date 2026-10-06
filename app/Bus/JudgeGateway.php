<?php

namespace App\Bus;

interface JudgeGateway
{
    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function judgeToolFailure(array $state): array;
}
