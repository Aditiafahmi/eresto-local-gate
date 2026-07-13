<?php

namespace App\Http\Controllers\Mock;

use App\Http\Controllers\Controller;
use App\Services\Mock\HikvisionStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HikvisionIsapiController extends Controller
{
    public function personSearch(
        Request $request,
        HikvisionStateStore $store
    ): JsonResponse {
        $this->ensureAvailable();

        $condition = $request->input('UserInfoSearchCond', []);
        $employeeNo = is_array($condition) ? ($condition['employeeNo'] ?? null) : null;
        $persons = $store->searchPersons(is_string($employeeNo) ? $employeeNo : null);

        return response()->json([
            'UserInfoSearch' => [
                'searchID' => is_array($condition) ? ($condition['searchID'] ?? '') : '',
                'responseStatusStrg' => 'OK',
                'numOfMatches' => count($persons),
                'totalMatches' => count($persons),
                'UserInfo' => $persons,
            ],
        ]);
    }

    public function personRecord(
        Request $request,
        HikvisionStateStore $store
    ): JsonResponse {
        $this->ensureAvailable();

        $action = $store->upsertPerson($this->objectPayload($request, 'UserInfo'));

        return $this->success('UserInfo', $action);
    }

    public function personDelete(
        Request $request,
        HikvisionStateStore $store
    ): JsonResponse {
        $this->ensureAvailable();

        $employeeNos = $this->employeeNos($request, 'UserInfoDelCond');
        $deleted = $store->deletePersons($employeeNos);

        return $this->success('UserInfo', 'deleted', ['deleted' => $deleted]);
    }

    public function cardSearch(
        Request $request,
        HikvisionStateStore $store
    ): JsonResponse {
        $this->ensureAvailable();

        $condition = $request->input('CardInfoSearchCond', []);
        $employeeNo = is_array($condition) ? ($condition['employeeNo'] ?? null) : null;
        $cardNo = is_array($condition) ? ($condition['cardNo'] ?? null) : null;
        $cards = $store->searchCards(
            is_string($employeeNo) ? $employeeNo : null,
            is_string($cardNo) ? $cardNo : null
        );

        return response()->json([
            'CardInfoSearch' => [
                'searchID' => is_array($condition) ? ($condition['searchID'] ?? '') : '',
                'responseStatusStrg' => 'OK',
                'numOfMatches' => count($cards),
                'totalMatches' => count($cards),
                'CardInfo' => $cards,
            ],
        ]);
    }

    public function cardRecord(
        Request $request,
        HikvisionStateStore $store
    ): JsonResponse {
        $this->ensureAvailable();

        $action = $store->upsertCard($this->objectPayload($request, 'CardInfo'));

        return $this->success('CardInfo', $action);
    }

    public function cardDelete(
        Request $request,
        HikvisionStateStore $store
    ): JsonResponse {
        $this->ensureAvailable();

        $employeeNos = $this->employeeNos($request, 'CardInfoDelCond');
        $deleted = $store->deleteCards($employeeNos);

        return $this->success('CardInfo', 'deleted', ['deleted' => $deleted]);
    }

    public function faceSearch(
        Request $request,
        HikvisionStateStore $store
    ): JsonResponse {
        $this->ensureAvailable();

        $fpid = $request->input('FPID');
        $fdid = $request->input('FDID');
        $faces = $store->searchFaces(
            is_string($fpid) ? $fpid : null,
            is_scalar($fdid) ? (string) $fdid : null
        );

        return response()->json([
            'numOfMatches' => count($faces),
            'totalMatches' => count($faces),
            'MatchList' => $faces,
            'FaceSearch' => [
                'numOfMatches' => count($faces),
                'totalMatches' => count($faces),
            ],
        ]);
    }

    public function faceRecord(
        Request $request,
        HikvisionStateStore $store
    ): JsonResponse {
        $this->ensureAvailable();

        [$record, $imageContent] = $this->facePayload($request, 'FaceDataRecord');
        $action = $store->upsertFace($record, $imageContent);

        return $this->success('FaceDataRecord', $action, [
            'FDID' => $record['FDID'] ?? null,
            'FPID' => $record['FPID'] ?? null,
        ]);
    }

    public function faceModify(
        Request $request,
        HikvisionStateStore $store
    ): JsonResponse {
        $this->ensureAvailable();

        [$record, $imageContent] = $this->facePayload($request, 'faceURL');
        $action = $store->upsertFace($record, $imageContent);

        return $this->success('FaceDataRecord', $action, [
            'FDID' => $record['FDID'] ?? null,
            'FPID' => $record['FPID'] ?? null,
        ]);
    }

    public function state(HikvisionStateStore $store): JsonResponse
    {
        $this->ensureAvailable();

        return response()->json(['data' => $store->snapshot()]);
    }

    public function reset(HikvisionStateStore $store): JsonResponse
    {
        $this->ensureAvailable();
        $store->reset();

        return response()->json([
            'message' => 'Mock Hikvision state reset.',
            'device' => config('mock.hikvision.device_name'),
        ]);
    }

    private function ensureAvailable(): void
    {
        abort_unless(
            app()->environment(['local', 'testing'])
                && (bool) config('mock.hikvision.server_enabled', false),
            404
        );
    }

    private function objectPayload(Request $request, string $key): array
    {
        $payload = $request->input($key);

        abort_unless(is_array($payload), 422, "{$key} payload is required.");

        return $payload;
    }

    private function employeeNos(Request $request, string $conditionKey): array
    {
        $condition = $request->input($conditionKey, []);
        $employeeNoList = is_array($condition)
            ? ($condition['EmployeeNoList'] ?? [])
            : [];

        if (! is_array($employeeNoList)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): mixed => is_array($item)
                ? ($item['employeeNo'] ?? null)
                : null,
            $employeeNoList
        ), static fn (mixed $employeeNo): bool => is_string($employeeNo) && $employeeNo !== ''));
    }

    private function facePayload(Request $request, string $recordField): array
    {
        $multipart = $this->multipartParts($request);
        $recordValue = $request->input($recordField)
            ?? ($multipart[$recordField]['contents'] ?? null);

        if (is_array($recordValue)) {
            $record = $recordValue;
        } elseif (is_string($recordValue)) {
            $record = json_decode($recordValue, true);
        } else {
            $record = null;
        }

        $uploadedImage = $request->file('img');
        $imageContent = $uploadedImage?->getContent()
            ?? ($multipart['img']['contents'] ?? null);

        abort_unless(is_array($record), 422, "{$recordField} multipart field is required.");
        abort_unless(is_string($imageContent), 422, 'img multipart file is required.');

        return [$record, $imageContent];
    }

    private function multipartParts(Request $request): array
    {
        $contentType = $request->header('Content-Type', '');

        if (! is_string($contentType)
            || ! preg_match('/boundary=(?:"([^"]+)"|([^;]+))/', $contentType, $matches)) {
            return [];
        }

        $boundary = $matches[1] !== '' ? $matches[1] : trim($matches[2]);
        $parts = [];

        foreach (explode('--'.$boundary, $request->getContent()) as $rawPart) {
            if ($rawPart === '' || str_starts_with(trim($rawPart), '--')) {
                continue;
            }

            if (str_starts_with($rawPart, "\r\n")) {
                $rawPart = substr($rawPart, 2);
            }

            if (str_ends_with($rawPart, "\r\n")) {
                $rawPart = substr($rawPart, 0, -2);
            }

            $sections = explode("\r\n\r\n", $rawPart, 2);

            if (count($sections) !== 2
                || ! preg_match('/name="([^"]+)"/', $sections[0], $nameMatch)) {
                continue;
            }

            $parts[$nameMatch[1]] = ['contents' => $sections[1]];
        }

        return $parts;
    }

    private function success(string $resource, string $action, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'statusCode' => 1,
            'statusString' => 'OK',
            'subStatusCode' => 'ok',
            'resource' => $resource,
            'action' => $action,
        ], $extra));
    }
}
