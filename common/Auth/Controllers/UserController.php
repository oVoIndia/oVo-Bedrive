<?php namespace Common\Auth\Controllers;

use App\User;
use Auth;
use Common\Auth\Actions\PaginateUsers;
use Common\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Common\Auth\UserRepository;
use Common\Core\BaseController;
use Common\Auth\Requests\ModifyUsers;
use Laravel\Sanctum\PersonalAccessToken;

class UserController extends BaseController {

    /**
     * @var User
     */
    private $user;

    /**
     * @var UserRepository
     */
    private $userRepository;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Settings
     */
    private $settings;

    /**
     * @param User $user
     * @param UserRepository $userRepository
     * @param Request $request
     * @param Settings $settings
     */
    public function __construct(User $user, UserRepository $userRepository, Request $request, Settings $settings)
    {
        $this->user = $user;
        $this->request = $request;
        $this->userRepository = $userRepository;

        $this->middleware('auth', ['except' => ['show']]);
        $this->settings = $settings;
    }

    /**
     * @return JsonResponse
     */
    public function index()
    {
        $this->authorize('index', User::class);

        $pagination = app(PaginateUsers::class)
            ->execute($this->request->all());

        return $this->success(['pagination' => $pagination]);
    }

    /**
     * @param User $user
     * @return JsonResponse
     */
    public function show(User $user)
    {
        $relations = array_filter(explode(',', $this->request->get('with', '')));
        $relations = array_merge(['roles', 'social_profiles'], $relations);

        if ($this->settings->get('envato.enable')) {
            $relations[] = 'purchase_codes';
        }

        if (Auth::id() === $user->id) {
            $user->makeVisible(['api_token']);
            // TODO: remove after sanctum is added to all projects
            if (method_exists($user, 'tokens')) {
                $relations[] = 'tokens';
            }
        }

        $user->load($relations);

        $this->authorize('show', $user);

        return $this->success(['user' => $user]);
    }

    /**
     * @param ModifyUsers $request
     * @return JsonResponse
     */
    public function store(ModifyUsers $request)
    {
        $this->authorize('store', User::class);

        $user = $this->userRepository->create($request->all());

        return $this->success(['user' => $user], 201);
    }

    /**
     * @param User $user
     * @param ModifyUsers $request
     *
     * @return JsonResponse
     */
    public function update(User $user, ModifyUsers $request)
    {
        $this->authorize('update', $user);

        $user = $this->userRepository->update($user, $request->all());

        return $this->success(['user' => $user]);
    }

    /**
     * @param string $ids
     * @return JsonResponse
     */
    public function destroy($ids)
    {
        $userIds = explode(',', $ids);
        $this->authorize('destroy', [User::class, $userIds]);

        $users = $this->user->whereIn('id', $userIds)->get();

        // guard against current user or admin user deletion
        foreach ($users as $user) {
            if ($user->id === Auth::id()) {
                return $this->error("Could not delete currently logged in user: {$user->email}");
            }

            if ($user->is_admin) {
                return $this->error("Could not delete admin user: {$user->email}");
            }
        }

        $this->userRepository->deleteMultiple($users->pluck('id'));

        return $this->success();
    }
}
