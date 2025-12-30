<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LawyerSearchController extends Controller
{
    /**
     * Search for lawyers based on criteria
     */
    public function search(Request $request): JsonResponse
    {
        // Validate the request
        $request->validate([
            'query' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'experience_years' => 'nullable|integer|min:0|max:50',
        ]);

        // Mock lawyer data for now
        $mockLawyers = [
            [
                'id' => 1,
                'name' => 'دکتر محمد رضایی',
                'specialization' => 'حقوق مدنی',
                'location' => 'تهران',
                'experience_years' => 15,
                'rating' => 4.8,
                'phone' => '021-12345678',
                'email' => 'rezaei@weekila.com',
                'bio' => 'متخصص در امور قراردادها و دعاوی مدنی با بیش از ۱۵ سال تجربه',
                'languages' => ['فارسی', 'انگلیسی'],
                'verified' => true,
            ],
            [
                'id' => 2,
                'name' => 'دکتر سارا احمدی',
                'specialization' => 'حقوق خانواده',
                'location' => 'اصفهان',
                'experience_years' => 12,
                'rating' => 4.9,
                'phone' => '031-98765432',
                'email' => 'ahmedi@weekila.com',
                'bio' => 'متخصص در امور خانواده و طلاق با تجربه موفق در پرونده‌های پیچیده',
                'languages' => ['فارسی'],
                'verified' => true,
            ],
            [
                'id' => 3,
                'name' => 'دکتر علی محمدی',
                'specialization' => 'حقوق کیفری',
                'location' => 'مشهد',
                'experience_years' => 20,
                'rating' => 4.7,
                'phone' => '051-55566677',
                'email' => 'mohammadi@weekila.com',
                'bio' => 'وکیل پایه یک دادگستری متخصص در امور کیفری و جنایی',
                'languages' => ['فارسی', 'انگلیسی', 'عربی'],
                'verified' => true,
            ],
        ];

        // Apply filters
        $filteredLawyers = collect($mockLawyers);

        if ($request->filled('query')) {
            $query = $request->input('query');
            $filteredLawyers = $filteredLawyers->filter(function ($lawyer) use ($query) {
                return str_contains($lawyer['name'], $query) ||
                       str_contains($lawyer['specialization'], $query) ||
                       str_contains($lawyer['bio'], $query);
            });
        }

        if ($request->filled('specialization')) {
            $specialization = $request->input('specialization');
            $filteredLawyers = $filteredLawyers->filter(function ($lawyer) use ($specialization) {
                return str_contains($lawyer['specialization'], $specialization);
            });
        }

        if ($request->filled('location')) {
            $location = $request->input('location');
            $filteredLawyers = $filteredLawyers->filter(function ($lawyer) use ($location) {
                return str_contains($lawyer['location'], $location);
            });
        }

        if ($request->filled('experience_years')) {
            $minExperience = $request->input('experience_years');
            $filteredLawyers = $filteredLawyers->filter(function ($lawyer) use ($minExperience) {
                return $lawyer['experience_years'] >= $minExperience;
            });
        }

        return response()->json([
            'success' => true,
            'data' => [
                'lawyers' => $filteredLawyers->values(),
                'total' => $filteredLawyers->count(),
                'filters_applied' => [
                    'query' => $request->query,
                    'specialization' => $request->specialization,
                    'location' => $request->location,
                    'experience_years' => $request->experience_years,
                ],
            ],
            'message' => 'Lawyers retrieved successfully',
        ]);
    }

    /**
     * Verify a lawyer's credentials
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'lawyer_id' => 'required|integer',
        ]);

        $lawyerId = $request->lawyer_id;

        // Mock verification data
        $mockVerification = [
            'lawyer_id' => $lawyerId,
            'verified' => true,
            'verification_date' => now()->format('Y-m-d H:i:s'),
            'license_number' => 'WK-' . str_pad($lawyerId, 4, '0', STR_PAD_LEFT),
            'bar_association' => 'کانون وکلای جمهوری اسلامی ایران',
            'specializations' => ['حقوق مدنی', 'حقوق خانواده', 'حقوق قراردادها'],
            'last_updated' => now()->subDays(rand(1, 30))->format('Y-m-d'),
            'status' => 'active',
        ];

        return response()->json([
            'success' => true,
            'data' => $mockVerification,
            'message' => 'Lawyer verification details retrieved successfully',
        ]);
    }
}
