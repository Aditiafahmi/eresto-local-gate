<?php

namespace Tests\Feature\Mock;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class HikvisionApiTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/mock-hikvision-'.Str::uuid());

        config([
            'mock.hikvision.server_enabled' => true,
            'mock.hikvision.device_name' => 'test-gate',
            'mock.hikvision.storage_path' => $this->storagePath,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storagePath);

        parent::tearDown();
    }

    public function test_it_persists_and_updates_person_and_card_records(): void
    {
        $this->postJson('/ISAPI/AccessControl/UserInfo/Record?format=json', [
            'UserInfo' => $this->person('M123', 'Original Name'),
        ], $this->digestHeaders())->assertOk()->assertJsonPath('action', 'created');

        $this->postJson('/ISAPI/AccessControl/CardInfo/Record?format=json', [
            'CardInfo' => $this->card('M123', 'CARD-M123'),
        ], $this->digestHeaders())->assertOk()->assertJsonPath('action', 'created');

        $this->postJson('/ISAPI/AccessControl/UserInfo/Search?format=json', [
            'UserInfoSearchCond' => ['employeeNo' => 'M123'],
        ], $this->digestHeaders())->assertOk()
            ->assertJsonPath('UserInfoSearch.numOfMatches', 1)
            ->assertJsonPath('UserInfoSearch.UserInfo.0.name', 'Original Name');

        $this->postJson('/ISAPI/AccessControl/CardInfo/Search?format=json', [
            'CardInfoSearchCond' => ['employeeNo' => 'M123'],
        ], $this->digestHeaders())->assertOk()
            ->assertJsonPath('CardInfoSearch.numOfMatches', 1)
            ->assertJsonPath('CardInfoSearch.CardInfo.0.cardNo', 'CARD-M123');

        $this->putJson('/ISAPI/AccessControl/UserInfo/Modify?format=json', [
            'UserInfo' => $this->person('M123', 'Updated Name'),
        ], $this->digestHeaders())->assertOk()->assertJsonPath('action', 'updated');

        $this->putJson('/ISAPI/AccessControl/CardInfo/Modify?format=json', [
            'CardInfo' => $this->card('M123', 'CARD-UPDATED'),
        ], $this->digestHeaders())->assertOk()->assertJsonPath('action', 'updated');

        $this->getJson('/__mock/hikvision/state')
            ->assertOk()
            ->assertJsonPath('data.device', 'test-gate')
            ->assertJsonPath('data.persons.0.name', 'Updated Name')
            ->assertJsonPath('data.cards.0.cardNo', 'CARD-UPDATED');
    }

    public function test_it_persists_and_replaces_a_face_image(): void
    {
        $record = json_encode([
            'faceLibType' => 'blackFD',
            'FDID' => '1',
            'FPID' => 'M123',
        ]);

        $this->call(
            'POST',
            '/ISAPI/Intelligent/FDLib/FaceDataRecord?format=json',
            ['FaceDataRecord' => $record],
            [],
            ['img' => UploadedFile::fake()->createWithContent('M123.jpg', 'first-image')],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->digestServerHeaders())
        )->assertOk()->assertJsonPath('action', 'created');

        $this->postJson('/ISAPI/Intelligent/FDLib/FDSearch?format=json', [
            'FDID' => '1',
            'FPID' => 'M123',
        ], $this->digestHeaders())->assertOk()->assertJsonPath('numOfMatches', 1);

        $this->call(
            'PUT',
            '/ISAPI/Intelligent/FDLib/FDModify?format=json',
            ['faceURL' => $record],
            [],
            ['img' => UploadedFile::fake()->createWithContent('M123.jpg', 'updated-image')],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->digestServerHeaders())
        )->assertOk()->assertJsonPath('action', 'updated');

        $this->getJson('/__mock/hikvision/state')
            ->assertOk()
            ->assertJsonPath('data.faces.0.FPID', 'M123')
            ->assertJsonPath('data.faces.0.bytes', strlen('updated-image'))
            ->assertJsonPath('data.faces.0.sha256', hash('sha256', 'updated-image'));

        $this->assertFileExists(
            $this->storagePath.'/test-gate/'.$this->getJson('/__mock/hikvision/state')
                ->json('data.faces.0.file')
        );
    }

    public function test_it_deletes_customer_state_and_can_be_reset(): void
    {
        $this->postJson('/ISAPI/AccessControl/UserInfo/Record', [
            'UserInfo' => $this->person('M123', 'Delete Me'),
        ], $this->digestHeaders())->assertOk();

        $this->postJson('/ISAPI/AccessControl/CardInfo/Record', [
            'CardInfo' => $this->card('M123', 'CARD-M123'),
        ], $this->digestHeaders())->assertOk();

        $this->putJson('/ISAPI/AccessControl/CardInfo/Delete', [
            'CardInfoDelCond' => [
                'EmployeeNoList' => [['employeeNo' => 'M123']],
            ],
        ], $this->digestHeaders())->assertOk()->assertJsonPath('deleted', 1);

        $this->putJson('/ISAPI/AccessControl/UserInfo/Delete', [
            'UserInfoDelCond' => [
                'EmployeeNoList' => [['employeeNo' => 'M123']],
            ],
        ], $this->digestHeaders())->assertOk()->assertJsonPath('deleted', 1);

        $this->deleteJson('/__mock/hikvision/state')
            ->assertOk();

        $this->getJson('/__mock/hikvision/state')
            ->assertOk()
            ->assertJsonCount(0, 'data.persons')
            ->assertJsonCount(0, 'data.cards')
            ->assertJsonCount(0, 'data.faces');
    }

    public function test_it_is_not_available_when_disabled(): void
    {
        config(['mock.hikvision.server_enabled' => false]);

        $this->getJson('/__mock/hikvision/state')->assertNotFound();
        $this->postJson('/ISAPI/AccessControl/UserInfo/Search', [])->assertNotFound();
    }

    public function test_isapi_requires_a_digest_authentication_handshake(): void
    {
        $this->postJson('/ISAPI/AccessControl/UserInfo/Search', [])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    }

    private function person(string $employeeNo, string $name): array
    {
        return [
            'employeeNo' => $employeeNo,
            'name' => $name,
            'userType' => 'normal',
            'Valid' => ['enable' => true],
        ];
    }

    private function card(string $employeeNo, string $cardNo): array
    {
        return [
            'employeeNo' => $employeeNo,
            'cardNo' => $cardNo,
            'cardType' => 'normal',
        ];
    }

    private function digestHeaders(): array
    {
        return ['Authorization' => 'Digest username="admin"'];
    }

    private function digestServerHeaders(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Digest username="admin"'];
    }
}
