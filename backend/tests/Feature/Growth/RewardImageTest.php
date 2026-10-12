<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Services\ImageAttachmentService;
use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RewardImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_create_reward_with_compressed_image(): void
    {
        Storage::fake('local');
        ['parent' => $parent] = $this->makeFamily();

        $file = $this->makeJpegUpload('toy.jpg', 1800, 1200);

        $created = $this->actingAsParent($parent)
            ->post('/api/v1/parent/rewards', [
                'name' => '遥控车',
                'kind' => 'physical',
                'coin_price' => 30,
                'status' => 'on_shelf',
                'image' => $file,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->assertNotNull($created['image_attachment_id']);
        $this->assertSame('/api/v1/parent/rewards/'.$created['id'].'/image', $created['image_url']);

        $attachment = Attachment::query()->findOrFail($created['image_attachment_id']);
        $this->assertSame('image/jpeg', $attachment->mime);
        $this->assertLessThanOrEqual(ImageAttachmentService::MAX_BYTES, $attachment->size_bytes);
        Storage::disk('local')->assertExists($attachment->path);

        $this->actingAsParent($parent)
            ->get('/api/v1/parent/rewards/'.$created['id'].'/image')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_deleting_reward_removes_image_file(): void
    {
        Storage::fake('local');
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();

        $created = $this->actingAsParent($parent)
            ->post('/api/v1/parent/rewards', [
                'name' => '贴纸包',
                'kind' => 'physical',
                'coin_price' => 5,
                'status' => 'draft',
                'image' => $this->makeJpegUpload('sticker.jpg', 800, 800),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $attachment = Attachment::query()->findOrFail($created['image_attachment_id']);
        $path = $attachment->path;
        Storage::disk('local')->assertExists($path);

        $this->actingAsParent($parent)
            ->deleteJson('/api/v1/parent/rewards/'.$created['id'])
            ->assertOk();

        $this->assertSoftDeleted('rewards', ['id' => $created['id'], 'family_id' => $family->id]);
        $this->assertSoftDeleted('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull(Reward::withTrashed()->findOrFail($created['id'])->image_attachment_id);
    }

    public function test_kid_can_view_on_shelf_reward_image(): void
    {
        Storage::fake('local');
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $created = $this->actingAsParent($parent)
            ->post('/api/v1/parent/rewards', [
                'name' => '野餐',
                'kind' => 'family_activity',
                'coin_price' => 40,
                'status' => RewardStatus::OnShelf->value,
                'image' => $this->makeJpegUpload('picnic.jpg', 640, 480),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->loginKid($child->fresh(['member.user']), $device, $token);

        $this->getJson('/api/v1/kid/shop')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', '/api/v1/kid/shop/'.$created['id'].'/image');

        $this->get('/api/v1/kid/shop/'.$created['id'].'/image')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    private function makeJpegUpload(string $name, int $width, int $height): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'reward');
        $this->assertNotFalse($tmp);
        $path = $tmp.'.jpg';
        rename($tmp, $path);

        $img = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($img);
        for ($y = 0; $y < $height; $y += 8) {
            for ($x = 0; $x < $width; $x += 8) {
                $color = imagecolorallocate($img, ($x * 7) % 255, ($y * 11) % 255, ($x + $y) % 255);
                imagefilledrectangle($img, $x, $y, $x + 7, $y + 7, $color);
            }
        }
        imagejpeg($img, $path, 95);
        imagedestroy($img);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }
}
