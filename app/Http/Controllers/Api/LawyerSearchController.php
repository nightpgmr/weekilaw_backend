<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class LawyerSearchController extends Controller
{
    /**
     * Search for lawyers using the external API
     */
    public function search(Request $request): JsonResponse
    {
        // Validate required fields
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'family' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if either mobile or license number is provided
        if (empty($request->mobileNumber) && empty($request->licenseNumber)) {
            return response()->json([
                'success' => false,
                'message' => 'Either mobile number or license number is required'
            ], 422);
        }

        // Prepare the search data
        $searchData = [
            'name' => $request->name,
            'family' => $request->family,
            'licensenumber' => $request->licenseNumber ?? '',
            'mobileNumber' => $request->mobileNumber ?? '',
            'EName' => $request->EName ?? '',
            'ELName' => $request->ELName ?? '',
            'address' => $request->address ?? '',
            'gender' => $request->gender ?? '',
            'province' => $request->province ?? '',
            'workstate' => $request->workstate ?? '',
            'proexperience' => $request->proexperience ?? ''
        ];

        try {
            // Make request to external API
            $response = Http::timeout(30)
                ->withOptions([
                    'verify' => false, // Disable SSL certificate verification
                ])
                ->post('https://search.icbar.org/App/Handler/Law.ashx?Method=mGetLawyers', $searchData);

            if ($response->successful()) {
                $data = $response->json();

                return response()->json([
                    'success' => true,
                    'data' => $data,
                    'message' => 'Lawyer search completed successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'External API request failed',
                    'status_code' => $response->status()
                ], $response->status());
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while searching for lawyers',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
