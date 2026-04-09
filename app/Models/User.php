<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'usuario',
        'email',
        'telefono',
        'password',
        'rol',
        'region',
        'abscripcion',
        'status', // 1 o 0
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function abscripcion()
    {
        return $this->hasOne(Abscripcion::class, 'abscripcion');
    }

    public function region()
    {
        return $this->hasOne(Region::class, 'region');
    }

    public function usuarios_id()
    {
        return $this->hasMany(Carpeta::class, 'id_usuarios');
    }

    public function lastSession()
    {
        return $this->hasOne(Session::class, 'user_id', 'id')
            ->latest('last_activity');
    }

    protected $appends = ['is_online', 'last_seen'];

    public function getIsOnlineAttribute()
    {
        if (! $this->lastSession) {
            return false;
        }

        $lastActivityTimestamp = $this->lastSession->last_activity;

        return Carbon::createFromTimestamp($lastActivityTimestamp)
            ->gt(now()->subMinutes(5));
    }

    public function getLastSeenAttribute()
    {
        if (! $this->lastSession) {
            return 'Nunca';
        }

        $lastActivityTimestamp = $this->lastSession->last_activity;

        return Carbon::createFromTimestamp($lastActivityTimestamp)->diffForHumans();
    }

    public function libroOficiosElabora()
    {
        return $this->hasMany(LibroOficios::class, 'id_user_elabora');
    }

    public function libroOficiosAsignado()
    {
        return $this->hasMany(LibroOficios::class, 'id_user_asigno');
    }

    public function informesScaners()
    {
        return $this->hasMany(InformesEscaner::class, 'id_usuario_escaneo');
    }
}