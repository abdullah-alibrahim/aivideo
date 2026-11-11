<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reel extends Model
{
    protected $fillable = [
        'title',
        'handle',
        'topic',
        'user_prompt',
        'niche',
        'target_country',
        'goal',
        'language',
        'style',
        'theme',
        'template',
        'format_type',
        'aspect_ratio',
        'anti_border_warning',
        'camera_motion',
        'scenes_count',
        'generated_script',
        'caption',
        'hashtags',
        'content_source',
        'ai_model',
        'output_video_path',
        'output_video_url',
        'thumbnail_path',
        'subtitle_path',
        'bg_music_path',
        'input_videos',
        'duration',
        'status',
        'cancel_requested_at',
        'cancelled_at',
        'progress',
        'progress_stage',
        'render_options',
        'score',
        'error_message',
        'collection_id',
        'collection_position',
        'quality_report',
    ];

    protected $casts = [
        'input_videos' => 'array',
        'duration' => 'float',
        'cancel_requested_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'anti_border_warning' => 'boolean',
        'scenes_count' => 'integer',
        'progress' => 'integer',
        'render_options' => 'array',
        'score' => 'integer',
        'collection_position' => 'integer',
        'quality_report' => 'array',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ReelCollection::class, 'collection_id');
    }

    public function getStreamUrlAttribute()
    {
        return route('reels.stream', $this->id);
    }
}
