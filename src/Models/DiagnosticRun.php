<?php

declare(strict_types=1);

namespace Aivo\Models;

use Illuminate\Database\Eloquent\Model;

class DiagnosticRun extends Model
{
    protected $table = 'diagnostic_runs';

    protected $fillable = [
        'user_id',
        'brand',
        'category',
        'coda_score',
        'psos_score',
        'results',
    ];

    protected $casts = [
        'results'    => 'array',
        'coda_score' => 'integer',
        'psos_score' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
