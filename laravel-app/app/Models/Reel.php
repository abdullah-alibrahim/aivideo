<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'bg_music_path',
        'input_videos',
        'duration',
        'status',
        'progress',
        'progress_stage',
        'score',
        'error_message',
    ];

    protected $casts = [
        'input_videos' => 'array',
        'duration' => 'float',
        'anti_border_warning' => 'boolean',
        'scenes_count' => 'integer',
        'progress' => 'integer',
        'score' => 'integer',
    ];

    public function getStreamUrlAttribute()
    {
        return route('reels.stream', $this->id);
    }
}
