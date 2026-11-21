<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** A stand-in for the host's user model. */
final class Participant extends Model
{
    protected $table = 'participants';

    protected $guarded = [];
}
