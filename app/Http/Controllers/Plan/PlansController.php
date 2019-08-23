<?php

namespace App\Http\Controllers\Plan;

use App\Traits\AccessControlAPI;
use App\Http\Controllers\APIController;
use App\Models\Plan\Plan;
use App\Traits\CheckProjectMembership;
use App\Models\Plan\PlanDay;
use App\Models\Plan\UserPlan;
use App\Models\Playlist\Playlist;
use Illuminate\Http\Request;

class PlansController extends APIController
{
    use AccessControlAPI;
    use CheckProjectMembership;

    /**
     * Display a listing of the resource.
     *
     * @OA\Get(
     *     path="/plans",
     *     tags={"Plans"},
     *     summary="List a user's plans",
     *     description="",
     *     operationId="v4_plans.index",
     *     @OA\Parameter(
     *          name="featured",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Plan/properties/featured"),
     *          description="Return featured plans"
     *     ),
     *     security={{"api_token":{}}},
     *     @OA\Parameter(ref="#/components/parameters/version_number"),
     *     @OA\Parameter(ref="#/components/parameters/key"),
     *     @OA\Parameter(ref="#/components/parameters/pretty"),
     *     @OA\Parameter(ref="#/components/parameters/format"),
     *     @OA\Parameter(ref="#/components/parameters/limit"),
     *     @OA\Parameter(ref="#/components/parameters/page"),
     *     @OA\Parameter(ref="#/components/parameters/sort_by"),
     *     @OA\Parameter(ref="#/components/parameters/sort_dir"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(ref="#/components/schemas/v4_plan_index"))
     *     )
     * )
     * 
     *
     * @return mixed
     * 
     * 
     * @OA\Schema (
     *   type="array",
     *   schema="v4_plan_index",
     *   description="The v4 plan index response.",
     *   title="User plan",
     *   @OA\Xml(name="v4_plan_index"),
     *   @OA\Items(ref="#/components/schemas/Plan")
     * )
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Validate Project / User Connection
        if (!empty($user) && !$this->compareProjects($user->id, $this->key)) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $featured = checkParam('featured');
        $featured = $featured && $featured != 'false' || empty($user);
        $limit        = (int) (checkParam('limit') ?? 25);
        $sort_by    = checkParam('sort_by') ?? 'name';
        $sort_dir   = checkParam('sort_dir') ?? 'asc';

        $plans = Plan::with('days')
            ->with('user')
            ->when($featured || empty($user), function ($q) {
                $q->where('plans.featured', '1');
            })->unless($featured, function ($q) use ($user) {
                $q->join('user_plans', function ($join) use ($user) {
                    $join->on('user_plans.plan_id', '=', 'plans.id')->where('user_plans.user_id', $user->id);
                });
            })
            ->orderBy($sort_by, $sort_dir)->paginate($limit);

        foreach ($plans as $plan) {
            $plan->total_days = sizeof($plan->days);
            unset($plan->days);
        }

        return $this->reply($plans);
    }

    /**
     * Store a newly created plan in storage.
     *
     * @OA\Post(
     *     path="/plans",
     *     tags={"Plans"},
     *     summary="Crete a plan",
     *     description="",
     *     operationId="v4_plans.store",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(ref="#/components/parameters/version_number"),
     *     @OA\Parameter(ref="#/components/parameters/key"),
     *     @OA\Parameter(ref="#/components/parameters/pretty"),
     *     @OA\Parameter(ref="#/components/parameters/format"),
     *     @OA\RequestBody(required=true, description="Fields for User Plan Creation",
     *           @OA\MediaType(mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(property="name", ref="#/components/schemas/Plan/properties/name"),
     *                  @OA\Property(property="suggested_start_date", type="string"),
     *                  @OA\Property(property="days",type="integer")
     *              )
     *          )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="text/csv",         @OA\Schema(ref="#/components/schemas/v4_plan_index"))
     *     )
     * )
     *
     * @return \Illuminate\Http\Response|array
     */
    public function store(Request $request)
    {

        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $name = checkParam('name', true);
        $days = checkParam('days', true);
        $suggested_start_date = checkParam('suggested_start_date');

        $plan = Plan::create([
            'user_id'               => $user->id,
            'name'                  => $name,
            'featured'              => false,
            'suggested_start_date'  => $suggested_start_date
        ]);

        for ($i = 0; $i < intval($days); $i++) {
            $playlist = Playlist::create([
                'user_id'               => $user->id,
            ]);

            PlanDay::create([
                'plan_id'               => $plan->id,
                'playlist_id'           => $playlist->id,
            ]);
        }

        UserPlan::create([
            'user_id'               => $user->id,
            'plan_id'               => $plan->id
        ]);

        $plan = $this->getPlan($plan->id, $user);
        return $this->reply($plan);
    }


    /**
     *
     * @OA\Get(
     *     path="/plans/{plan_id}",
     *     tags={"Plans"},
     *     summary="A user's plan",
     *     description="",
     *     operationId="v4_plans.show",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="plan_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/User/properties/id"),
     *          description="The plan id"
     *     ),
     *     @OA\Parameter(ref="#/components/parameters/version_number"),
     *     @OA\Parameter(ref="#/components/parameters/key"),
     *     @OA\Parameter(ref="#/components/parameters/pretty"),
     *     @OA\Parameter(ref="#/components/parameters/format"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(ref="#/components/schemas/v4_plan_index"))
     *     )
     * )
     *
     * @param $plan_id
     *
     * @return mixed
     * 
     * 
     */
    public function show(Request $request, $plan_id)
    {
        $user = $request->user();

        // Validate Project / User Connection
        if (!empty($user) && !$this->compareProjects($user->id, $this->key)) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $plan = $this->getPlan($plan_id, $user);;

        if (!$plan) {
            return $this->setStatusCode(404)->replyWithError('Plan Not Found');
        }

        return $this->reply($plan);
    }

    /**
     * Update the specified plan.
     *
     * @OA\Put(
     *     path="/plans/{plan_id}",
     *     tags={"Plans"},
     *     summary="Update a plan",
     *     description="",
     *     operationId="v4_plans.update",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="plan_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Plan/properties/id")),
     *     @OA\Parameter(name="days", in="query",@OA\Schema(type="string"), description="Comma-separated ids of the days to be sorted or deleted"),
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="application/json",
     *          @OA\Schema(
     *              @OA\Property(property="name", ref="#/components/schemas/Plan/properties/name"),
     *              @OA\Property(property="suggested_start_date", type="string")
     *          )
     *     )),
     *     @OA\Parameter(ref="#/components/parameters/version_number"),
     *     @OA\Parameter(ref="#/components/parameters/key"),
     *     @OA\Parameter(ref="#/components/parameters/pretty"),
     *     @OA\Parameter(ref="#/components/parameters/format"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(ref="#/components/schemas/v4_plan_index"))
     *     )
     * )
     *
     * @param  int $plan_id
     * @param  string $days
     *
     * @return array|\Illuminate\Http\Response
     */
    public function update(Request $request, $plan_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $plan = Plan::where('user_id', $user->id)->where('id', $plan_id)->first();

        if (!$plan) {
            return $this->setStatusCode(404)->replyWithError('Plan Not Found');
        }

        $update_values = [];

        $name = checkParam('name');
        if ($name) {
            $update_values["name"] = $name;
        }

        $suggested_start_date = checkParam('suggested_start_date');
        if ($suggested_start_date) {
            $update_values["suggested_start_date"] = $suggested_start_date;
        }

        $plan->update($update_values);

        $days = checkParam('days');

        if ($days) {
            $days_ids = explode(',', $days);
            PlanDay::setNewOrder($days_ids);
            $deleted_days = PlanDay::whereNotIn('id', $days_ids)->where('plan_id', $plan->id);
            $playlists_ids = $deleted_days->pluck('playlist_id')->unique();
            $playlists = Playlist::whereIn('id', $playlists_ids);
            $deleted_days->delete();
            $playlists->delete();
        }

        $plan = $this->getPlan($plan->id, $user);

        return $this->reply($plan);
    }

    /**
     * Remove the specified plan.
     *
     * @OA\Delete(
     *     path="/plans/{plan_id}",
     *     tags={"Plans"},
     *     summary="Delete a plan",
     *     description="",
     *     operationId="v4_plans.destroy",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="plan_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Plan/properties/id")),
     *     @OA\Parameter(ref="#/components/parameters/version_number"),
     *     @OA\Parameter(ref="#/components/parameters/key"),
     *     @OA\Parameter(ref="#/components/parameters/pretty"),
     *     @OA\Parameter(ref="#/components/parameters/format"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(type="string")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(type="string")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(type="string")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(type="string"))
     *     )
     * )
     *
     * @param  int $plan_id
     *
     * @return array|\Illuminate\Http\Response
     */
    public function destroy(Request $request, $plan_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $plan = Plan::where('user_id', $user->id)->where('id', $plan_id)->first();

        if (!$plan) {
            return $this->setStatusCode(404)->replyWithError('Plan Not Found');
        }

        $playlists_ids = $plan->days()->pluck('playlist_id')->unique();
        $playlists = Playlist::whereIn('id', $playlists_ids);
        $playlists->delete();
        $user_plans = UserPlan::where('plan_id', $plan_id);
        $user_plans->delete();
        $plan->days()->delete();
        $plan->delete();

        return $this->reply('Plan Deleted');
    }

    private function validatePlan()
    {
        $validator = Validator::make(request()->all(), [
            'name'              => 'required|string'
        ]);
        if ($validator->fails()) {
            return ['errors' => $validator->errors()];
        }
        return true;
    }

    /**
     * Start the specified plan.
     *
     * @OA\Post(
     *     path="/plans/{plan_id}/start",
     *     tags={"Plans"},
     *     summary="Start a plan",
     *     description="",
     *     operationId="v4_plans.start",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="plan_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Plan/properties/id")),
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="application/json",
     *          @OA\Schema(
     *              @OA\Property(property="start_date", type="string")
     *          )
     *     )),
     *     @OA\Parameter(ref="#/components/parameters/version_number"),
     *     @OA\Parameter(ref="#/components/parameters/key"),
     *     @OA\Parameter(ref="#/components/parameters/pretty"),
     *     @OA\Parameter(ref="#/components/parameters/format"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_plan_index")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(ref="#/components/schemas/v4_plan_index"))
     *     )
     * )
     *
     * @param  int $plan_id
     *
     * @return array|\Illuminate\Http\Response
     */
    public function start(Request $request, $plan_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $plan = Plan::where('user_id', $user->id)->where('id', $plan_id)->first();

        if (!$plan) {
            return $this->setStatusCode(404)->replyWithError('Plan Not Found');
        }

        $start_date = checkParam('start_date', true);

        $user_plan = UserPlan::where('plan_id', $plan_id)->where('user_id', $user->id)->first();

        if (!$user_plan) {
            $user_plan = UserPlan::create([
                'user_id'               => $user->id,
                'plan_id'               => $plan->id
            ]);
        }

        $user_plan->start_date = $start_date;
        $user_plan->save();


        $plan = $this->getPlan($plan_id, $user);

        return $this->reply($plan);
    }

    /**
     * Store the newly created plan days.
     *
     * @OA\Post(
     *     path="/plans/{plan_id}/day",
     *     tags={"Plans"},
     *     summary="Create plan days",
     *     description="",
     *     operationId="v4_plans_days.store",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="plan_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Plan/properties/id")),
     *     @OA\Parameter(name="days", in="query", required=true, @OA\Schema(type="integer"), description="Number of days to add to the plan"),
     *     @OA\Parameter(ref="#/components/parameters/version_number"),
     *     @OA\Parameter(ref="#/components/parameters/key"),
     *     @OA\Parameter(ref="#/components/parameters/pretty"),
     *     @OA\Parameter(ref="#/components/parameters/format"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_plans_days")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_plans_days")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_plans_days")),
     *         @OA\MediaType(mediaType="text/csv",         @OA\Schema(ref="#/components/schemas/v4_plans_days"))
     *     )
     * )
     *
     * @OA\Schema (
     *   type="array",
     *   schema="v4_plans_days",
     *   description="The v4 plan days creation response.",
     *   title="User plan",
     *   @OA\Xml(name="v4_plans_days"),
     *   @OA\Items(ref="#/components/schemas/PlanDay")
     * )
     * @return mixed
     */
    public function storeDay(Request $request, $plan_id)
    {
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);
        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $plan = Plan::where('user_id', $user->id)->where('id', $plan_id)->first();

        if (!$plan) {
            return $this->setStatusCode(404)->replyWithError('Plan Not Found');
        }

        $days = checkParam('days', true);

        $created_plan_days = [];

        for ($i = 0; $i < intval($days); $i++) {
            $playlist = Playlist::create([
                'user_id'               => $user->id,
            ]);

            $created_plan_days[] = PlanDay::create([
                'plan_id'               => $plan->id,
                'playlist_id'           => $playlist->id,
            ]);
        }

        return $this->reply($created_plan_days);
    }

    private function getPlan($plan_id, $user)
    {
        $select = ['plans.*'];
        if (!empty($user)) {
            $select[] = 'user_plans.start_date';
            $select[] = 'user_plans.percentage_completed';
        }
        $plan = Plan::with('days')
            ->with('user')
            ->where('plans.id', $plan_id)
            ->when(!empty($user), function ($q) use ($user) {
                $q->leftJoin('user_plans', function ($join) use ($user) {
                    $join->on('user_plans.plan_id', '=', 'plans.id')->where('user_plans.user_id', $user->id);
                });
            })->select($select)->first();

        return $plan;
    }
}