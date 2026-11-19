<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class, 'user_id');
    }

    public function verifiedStudentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class, 'verified_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'owner_id');
    }

    public function rentalRequests(): HasMany
    {
        return $this->hasMany(RentalRequest::class, 'borrower_id');
    }

    public function proposedNegotiations(): HasMany
    {
        return $this->hasMany(RentalNegotiation::class, 'proposer_id');
    }

    public function respondedNegotiations(): HasMany
    {
        return $this->hasMany(RentalNegotiation::class, 'responded_by');
    }

    public function ownedTransactions(): HasMany
    {
        return $this->hasMany(RentalTransaction::class, 'owner_id');
    }

    public function borrowedTransactions(): HasMany
    {
        return $this->hasMany(RentalTransaction::class, 'borrower_id');
    }

    public function proposedRentalExtensions(): HasMany
    {
        return $this->hasMany(RentalExtension::class, 'proposer_id');
    }

    public function respondedRentalExtensions(): HasMany
    {
        return $this->hasMany(RentalExtension::class, 'responded_by');
    }

    public function reportedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'reported_by');
    }

    public function confirmedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'confirmed_by');
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function inAppNotifications(): HasMany
    {
        return $this->hasMany(InAppNotification::class, 'user_id');
    }

    public function submittedReports(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function receivedReports(): HasMany
    {
        return $this->hasMany(Report::class, 'reported_user_id');
    }

    public function reviewedReports(): HasMany
    {
        return $this->hasMany(Report::class, 'reviewed_by');
    }
}
