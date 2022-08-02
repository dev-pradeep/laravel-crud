<?php

namespace App\Http\Controllers;

use App\OwnerToTeamMember;
use App\Rules\FacebookGroupsBelongsToOwner;
use App\TeamMemberGroupAccess;
use App\User;
use App\FacebookGroups;
use Exception;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;
use Yajra\Datatables\Datatables;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Response as HttpResponse;

/**
 * Class TeamMembersController handles team members actions for the web routes
 *
 * @package App\Http\Controllers
 */
class TeamMembersController extends Controller
{
    /**
     * Sets the middleware and validation rules for this controller
     */
    protected function init()
    {
        $this->middleware('auth');
        $this->middleware('validate.ajax.request')->except([
            'updateStatus',
            'teamMembers',
            'getData',
        ]);

        $this->ajaxValidatorRules['store'] = [
            'name' => 'required|regex:/^[a-zA-Z\s\-\'\,]+$/u',
            'email' => 'required|email',
            'facebook_groups_id' => ['array', new FacebookGroupsBelongsToOwner()],
        ];
        $this->ajaxValidatorRules['update'] = [
            'facebook_groups_id' => ['array', new FacebookGroupsBelongsToOwner()],
        ];
        $this->ajaxValidatorRules['destroyTeamMembers'] = ['id' => 'required|numeric'];
        $this->ajaxValidatorRules['checkTeamMembersEmail'] = [
            'email' => 'required|email',
        ];
        $this->ajaxValidatorRules['getEmail'] = [
            'search' => 'required|email',
        ];
    }

    /**
     * Shows the team Members page.
     *
     * @return Renderable view team members with the owner and his owned groups
     */
    public function teamMembers()
    {
        $group = FacebookGroups::where('user_id', $this->currentUser->id)->get();
        $user = $this->request->user();

        return view('teammembers', compact('group', 'user'));
    }

    /**
     * Show the application Team Member Detail.
     *
     * @param int $id of the requested team member {@see User}
     *
     * @return JsonResponse containing:
     *                      1. the code that represent HTTP status code
     *                      2. message that returns proper text according to the code
     *                      3. user that contains team member if owner has access,
     *                         otherwise returns empty data parameter
     *                      4. fb_id that contains team member group ids if owner has access,
     *                         otherwise returns empty data parameter
     */
    public function getTeamMember(int $id)
    {
        $ownerHasAccess = OwnerToTeamMember::where('owner_id', $this->currentUser->id)
            ->where('team_member_id', $id)
            ->first();

        if (!$ownerHasAccess) {
            return response()->json(
                [
                    'code'    => Response::HTTP_UNAUTHORIZED,
                    'message' => 'Unauthorized',
                    'data'    => '',
                ]
            );
        }

        $user = User::with('teamMemberGroupAccess')->find($id);
        $groupIds = $user->teamMemberGroupAccess->pluck('id');

        return response()->json([
            'code'    => Response::HTTP_OK,
            'message' => 'Successfully.',
            'user'    => $user,
            'fb_id'   => $groupIds,
        ]);
    }

    /**
     * Shows team members of the logged-in owner
     *
     * @return Renderable with filtered team members
     *
     * @throws Exception if {@see \Yajra\DataTables\DataTables::make} fails
     */
    public function getData()
    {
        $teamMemberIds = DB::table('owner_to_team_members')
            ->where('owner_id', auth()->user()->id)
            ->pluck('team_member_id');
        $user = User::with('teamMemberGroupAccess')->whereIn('id', $teamMemberIds);

        if ($this->request->get('name')) {
            $user->where('name', 'like', "%{$this->request->get('name')}%");
        }
        if ($this->request->get('email')) {
            $user->where('email', 'like', "%{$this->request->get('email')}%");
        }

        return Datatables::of($user)
            ->addColumn('facebook_groups_id', function ($user) {
                return $user->teamMemberGroupAccess->pluck('fb_name')->all();
            })
            ->editColumn('status', function ($user) {
                return $user->status ? 'Active' : 'Inactive';
            })
            ->make();
    }

    /**
     * If the user can add team members (due to plan limitation):
     *     1. Stores new team member account in the owner's team
     *     2. Assigns provided facebook groups to the team member
     *
     * @return HttpResponse containing:
     *                      1. the status that contains success message if the team member is successfully saved,
     *                         otherwise an error
     *                      2. message that returns proper text according to the status
     *                      3. data that returns hide_create_button parameter if the owner has reached
     *                         the limit of members capacity, otherwise empty array
     *
     * @throws ApiErrorException if the {@see \Stripe\ApiRequestor::request} fails
     */
    public function store()
    {
        if (!$this->currentUser->canAddTeamMembers()) {
            return response([
                'message' => 'You have reached the limit of the adding new team members',
                'data' => [ 'hide_create_button' => true],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $user = app(User::class)->addTeamMember(
            $this->currentUser->id,
            $this->request->only(['email', 'name', 'facebook_groups_id'])
        );

        return response(
            [
                'message' => $user['message'],
                'data'    => ['hide_create_button' => !$this->currentUser->canAddTeamMembers()],
            ],
            ($user['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST)
        );
    }

    /**
     * Manage team member group access
     *
     * @param int $id of the team member's system user to be assigned to this owner's team
     *
     * @return JsonResponse containing:
     *                      1. the status that contains success message if the team member is saved, otherwise an error
     *                      2. message that returns proper text according to the status
     *                      3. data that returns hide_create_button parameter if the owner has reached
     *                         the limit of members capacity, otherwise empty array
     *
     * @throws ApiErrorException if the {@see \Stripe\ApiRequestor::request} fails
     */
    public function update(int $id)
    {
        $alreadyOnTeam = OwnerToTeamMember::where('owner_id', $this->currentUser->id)
            ->where('team_member_id', $id)
            ->first();

        if (!$alreadyOnTeam) {
            return response()->json([
                'status' => 'error',
                'message' => 'This user is not currently part of your team.',
                'data' => [],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $teamMemberAssignments = [];
        foreach ($this->request->facebook_groups_id ?? [] as $facebookGroupId) {
            $teamMemberAssignments[$facebookGroupId] = [
                'owner_to_team_member_id' => $alreadyOnTeam->id,
            ];
        }

        /*
         * This will override all existing facebook groups that user has access to and replace them with the
         * groups send in parameter. Overriding is needed if we want to remove access to group for a user.
         * @todo Handle this differently from the frontend so instead of overriding, we're only adding new ones
         */
        User::find($id)->teamMemberGroupAccess()->sync($teamMemberAssignments);

        return response()->json([
            'status' => 'success',
            'message' => 'Team Member Details Updated Successfully.',
            'data' => ['hide_create_button' => !$this->currentUser->canAddTeamMembers()],
        ]);
    }

    /**
     * Removes the team member from user
     *
     * @return JsonResponse containing success message, users team member count if member is deleted
     *                      otherwise error message
     */
    public function destroyTeamMembers()
    {
        try {
            $ownerToTeamMember = OwnerToTeamMember::where('owner_id', $this->currentUser->id)
                ->where('team_member_id', $this->request->id)
                ->first();

            TeamMemberGroupAccess::where('owner_to_team_member_id', $ownerToTeamMember->id)
                ->delete();

            $ownerToTeamMember->delete();

            return response()->json(
                [
                    'code' => Response::HTTP_OK,
                    'message' => 'Team Members Deleted Successfully.',
                    'data' => ['hide_create_button' => !$this->currentUser->canAddTeamMembers()],
                ]
            );
        } catch (\Exception $e) {
            return response()->json(
                [
                    'code' => Response::HTTP_BAD_REQUEST,
                    'message' => $e->getMessage(),
                    'data' => '',
                ]
            );
        }
    }

    /**
     * Check if the entered email id exists or not in DB
     *
     * @return JsonResponse
     */
    public function checkTeamMembersEmail()
    {
        $user = User::where('email', $this->request->email)->first();
        $ownerToTeamMember = $user ?
            OwnerToTeamMember::where('owner_id', $this->currentUser->id)
                ->where('team_member_id', $user->id)
                ->count()
            :
            0;

        return response()->json(
            [
                'count' => $ownerToTeamMember,
                'data' => $user,
                'message' => 'successfully',
            ],
            Response::HTTP_OK
        );
    }

    /**
     * Get users email id for autosuggestion based on the entered email id
     *
     * @return JsonResponse
     */
    public function getEmail()
    {
        $users = User::orderby('email', 'asc')
            ->select('id', 'email')
            ->where('email', 'like', '%' . $this->request->search . '%')
            ->get();

        $response = [];
        foreach ($users as $user) {
            $response[] = ["value" => $user->id, "label" => $user->email];
        }

        return response()->json($response);
    }
}
