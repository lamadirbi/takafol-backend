<?php

namespace Tests\Feature\Concerns;

use App\Models\Camp;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

trait InteractsWithTakafol
{
    private int $takafolSeq = 0;

    protected function nextNationalId(string $prefix = '1'): string
    {
        $this->takafolSeq++;

        return $prefix.str_pad((string) $this->takafolSeq, 9, '0', STR_PAD_LEFT);
    }

    protected function forgetTenant(): void
    {
        App::forgetInstance('current_camp_id');
        App::forgetInstance('current_camp');
    }

    protected function setTenant(Camp $camp): void
    {
        App::instance('current_camp_id', $camp->id);
        App::instance('current_camp', $camp);
    }

    protected function makeCamp(array $overrides = []): Camp
    {
        $this->forgetTenant();

        return Camp::query()->create(array_merge([
            'name' => 'مخيم '.Str::random(6),
            'slug' => 'camp-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'landing_page_data' => [
                'hero_title' => 'عنوان تجريبي',
                'hero_description' => 'وصف تجريبي',
            ],
            'subscription_valid_until' => now()->addMonth()->toDateString(),
        ], $overrides));
    }

    protected function makeGlobalSuper(array $overrides = []): User
    {
        $this->forgetTenant();

        return User::query()->create(array_merge([
            'national_id' => $this->nextNationalId('9'),
            'name' => 'سوبر أدمن',
            'username' => 'super-'.Str::lower(Str::random(8)),
            'email' => 'super-'.Str::lower(Str::random(6)).'@takafol.test',
            'password' => 'SuperPassword123!',
            'role' => User::ROLE_ADMIN,
            'is_super' => true,
            'camp_id' => null,
        ], $overrides));
    }

    protected function makeCampAdmin(Camp $camp, array $overrides = []): User
    {
        $this->setTenant($camp);

        $admin = User::query()->create(array_merge([
            'national_id' => $this->nextNationalId('8'),
            'name' => 'مدير المخيم',
            'username' => 'admin-'.Str::lower(Str::random(8)),
            'email' => 'admin-'.Str::lower(Str::random(6)).'@takafol.test',
            'password' => 'AdminPass123',
            'role' => User::ROLE_ADMIN,
            'is_super' => false,
            'camp_id' => $camp->id,
        ], $overrides));

        if ($camp->primary_admin_user_id === null) {
            $camp->update(['primary_admin_user_id' => $admin->id]);
        }

        return $admin->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array{family: Family, user: User, serial: string}
     */
    protected function makeFamilyWithHead(Camp $camp, array $familyOverrides = [], array $members = []): array
    {
        $this->setTenant($camp);

        $nationalId = $familyOverrides['national_id'] ?? $this->nextNationalId('2');
        $headName = $familyOverrides['head_name'] ?? 'رب أسرة تجريبي';

        $user = User::query()->create([
            'national_id' => $nationalId,
            'name' => $headName,
            'password' => Str::random(32),
            'role' => User::ROLE_FAMILY_HEAD,
            'camp_id' => $camp->id,
        ]);
        $serial = User::defaultSerialFromId((int) $user->id);
        $user->password = $serial;
        $user->save();

        $family = Family::query()->create(array_merge([
            'user_id' => $user->id,
            'head_name' => $headName,
            'national_id' => $nationalId,
            'phone' => '0590000001',
            'social_status' => 'married',
            'financial_status' => 'low',
            'total_members' => max(1, count($members)),
            'camp_id' => $camp->id,
        ], $familyOverrides));

        if ($members === []) {
            $family->members()->create([
                'name' => $headName,
                'relationship' => 'رب الأسرة',
                'gender' => FamilyMember::GENDER_MALE,
                'date_of_birth' => now()->subYears(35)->toDateString(),
                'camp_id' => $camp->id,
            ]);
        } else {
            foreach ($members as $member) {
                $family->members()->create(array_merge([
                    'camp_id' => $camp->id,
                ], $member));
            }
        }

        $family->update(['total_members' => $family->members()->count()]);

        return [
            'family' => $family->fresh(['user', 'members']),
            'user' => $user->fresh(),
            'serial' => $serial,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function campHeaders(Camp $camp, ?string $token = null): array
    {
        $this->resetAuthGuards();

        $headers = [
            'Accept' => 'application/json',
            'X-Camp-Slug' => $camp->slug,
        ];
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    protected function superHeaders(?string $token = null): array
    {
        $this->resetAuthGuards();

        $headers = ['Accept' => 'application/json'];
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }

    protected function resetAuthGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function fakeJpeg(string $name = 'receipt.jpg'): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent(
            str_ends_with(strtolower($name), '.png') ? $name : 'receipt.png',
            $bytes
        );
    }

    protected function loginAdmin(User $admin, Camp $camp): string
    {
        $response = $this->postJson('/api/admin/login', [
            'username' => $admin->username,
            'password' => 'AdminPass123',
        ], $this->campHeaders($camp));

        $response->assertOk()->assertJsonStructure(['token']);

        return $response->json('token');
    }

    protected function loginSuper(User $super): string
    {
        $response = $this->postJson('/api/admin/login', [
            'username' => $super->username,
            'password' => 'SuperPassword123!',
        ], $this->superHeaders());

        $response->assertOk()->assertJsonStructure(['token']);

        return $response->json('token');
    }

    protected function loginFamily(User $user, string $serial, Camp $camp): string
    {
        $response = $this->postJson('/api/login', [
            'national_id' => $user->national_id,
            'serial' => $serial,
        ], $this->campHeaders($camp));

        $response->assertOk()->assertJsonStructure(['token']);

        return $response->json('token');
    }
}
