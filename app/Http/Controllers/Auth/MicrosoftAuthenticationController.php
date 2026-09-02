<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\CreateTeam;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class MicrosoftAuthenticationController extends Controller
{
    /**
     * Redirect the user to Microsoft for authentication.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('microsoft')->redirect();
    }

    /**
     * Authenticate the user returned by Microsoft.
     */
    public function callback(Request $request, CreateTeam $createTeam, LoginResponse $loginResponse): Response
    {
        if ($request->filled('error')) {
            return redirect()->route('login')->withErrors([
                'email' => __('Microsoft login was cancelled or could not be completed.'),
            ]);
        }

        $microsoftUser = Socialite::driver('microsoft')->user();
        $microsoftId = $microsoftUser->getId();
        $email = $microsoftUser->getEmail();

        if (! is_string($microsoftId) || $microsoftId === '' || ! is_string($email) || $email === '') {
            throw ValidationException::withMessages([
                'email' => __('Microsoft did not provide the account information required to log in.'),
            ]);
        }

        $email = Str::lower($email);
        $name = $microsoftUser->getName() ?: Str::before($email, '@');

        $user = DB::transaction(function () use ($createTeam, $email, $microsoftId, $name): User {
            $user = User::query()->where('microsoft_id', $microsoftId)->first();

            if ($user) {
                $user->update([
                    'name' => $name,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);

                return $user;
            }

            $user = User::query()->where('email', $email)->first();

            if ($user?->microsoft_id) {
                throw ValidationException::withMessages([
                    'email' => __('This email address is already linked to another Microsoft account.'),
                ]);
            }

            if ($user) {
                $user->update([
                    'microsoft_id' => $microsoftId,
                    'name' => $name,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);

                return $user;
            }

            $user = User::query()->create([
                'microsoft_id' => $microsoftId,
                'name' => $name,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Str::random(64),
            ]);

            $createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            return $user;
        });

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return $loginResponse->toResponse($request);
    }
}
