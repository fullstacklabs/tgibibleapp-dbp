<?php

namespace App\Http\Controllers\Dashboard\Organizations;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Organization\Organization;
use App\Models\User\Role;
use Illuminate\Support\Facades\Auth;
class OrganizationRolesController extends Controller
{
	public function __construct(Request $request)
	{
		$this->middleware('auth');
	}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
	public function index()
	{
		$user = \Auth::user();
		return view('dashboard.organizations.roles.index',compact('user'));
	}

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
	public function create()
	{
		$user = \Auth::user();
		$organizations = Organization::with('translations')->get();
		return view('dashboard.organizations.roles.create',compact('user','organizations'));
	}

	public function review()
	{
		$user = \Auth::user();
		return view('dashboard.organizations.roles.review',compact('user'));
	}

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
    	$user = \Auth::user();
        $role = new Role();
        $role->user_id = $user->id;
        $role->organization_id = $request->organization_id;
	    $role->role = "requesting-access";
        $role->save();
        redirect()->route('dashboard_organization_roles.index');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
    	$user = Auth::user();
    	$organization = Organization::find($id);
        return view('dashboard.organizations.roles.show',compact('organization','user'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
