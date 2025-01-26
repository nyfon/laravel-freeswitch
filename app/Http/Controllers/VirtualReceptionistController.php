<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Domain;
use App\Models\IvrMenus;
use App\Models\Recordings;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\IvrMenuOptions;
use App\Models\VoicemailGreetings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use App\Services\CallRoutingOptionsService;
use App\Http\Requests\StoreVirtualReceptionistRequest;
use App\Http\Requests\UpdateVirtualReceptionistRequest;
use App\Http\Requests\CreateVirtualReceptionistKeyRequest;
use App\Http\Requests\UpdateVirtualReceptionistKeyRequest;

class VirtualReceptionistController extends Controller
{
    public $model;
    public $filters = [];
    public $sortField;
    public $sortOrder;
    protected $viewName = 'VirtualReceptionists';
    protected $searchable = ['voicemail_id', 'voicemail_mail_to', 'extension.effective_caller_id_name'];

    public function __construct()
    {
        $this->model = new IvrMenus();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // Check permissions
        if (!userCheckPermission("ivr_menu_view")) {
            return redirect('/');
        }

        return Inertia::render(
            $this->viewName,
            [
                'data' => function () {
                    return $this->getData();
                },

                'routes' => [
                    'current_page' => route('virtual-receptionists.index'),
                    'store' => route('virtual-receptionists.store'),
                    'item_options' => route('virtual-receptionists.item.options'),
                ]
            ]
        );
    }

    /**
     *  Get data
     */
    public function getData($paginate = 50)
    {

        // Check if search parameter is present and not empty
        if (!empty(request('filterData.search'))) {
            $this->filters['search'] = request('filterData.search');
        }

        // Add sorting criteria
        $this->sortField = request()->get('sortField', 'ivr_menu_extension'); // Default to 'voicemail_id'
        $this->sortOrder = request()->get('sortOrder', 'asc'); // Default to descending

        $data = $this->builder($this->filters);

        // Apply pagination if requested
        if ($paginate) {
            $data = $data->paginate($paginate);
        } else {
            $data = $data->get(); // This will return a collection
        }

        // logger($data);

        return $data;
    }

    /**
     * @param  array  $filters
     * @return Builder
     */
    public function builder(array $filters = [])
    {
        $data =  $this->model::query();
        $domainUuid = session('domain_uuid');
        $data = $data->where($this->model->getTable() . '.domain_uuid', $domainUuid);
        // $data->with(['extension' => function ($query) use ($domainUuid) {
        //     $query->select('extension_uuid', 'extension', 'effective_caller_id_name')
        //         ->where('domain_uuid', $domainUuid);
        // }]);


        $data->select(
            'ivr_menu_uuid',
            'ivr_menu_name',
            'ivr_menu_extension',
            'ivr_menu_enabled',
            'ivr_menu_description',

        );

        if (is_array($filters)) {
            foreach ($filters as $field => $value) {
                if (method_exists($this, $method = "filter" . ucfirst($field))) {
                    $this->$method($data, $value);
                }
            }
        }

        // Apply sorting
        $data->orderBy($this->sortField, $this->sortOrder);

        return $data;
    }

    /**
     * @param $query
     * @param $value
     * @return void
     */
    protected function filterSearch($query, $value)
    {
        $searchable = $this->searchable;

        // Case-insensitive partial string search in the specified fields
        $query->where(function ($query) use ($value, $searchable) {
            foreach ($searchable as $field) {
                if (strpos($field, '.') !== false) {
                    // Nested field (e.g., 'extension.name_formatted')
                    [$relation, $nestedField] = explode('.', $field, 2);

                    $query->orWhereHas($relation, function ($query) use ($nestedField, $value) {
                        $query->where($nestedField, 'ilike', '%' . $value . '%');
                    });
                } else {
                    // Direct field
                    $query->orWhere($field, 'ilike', '%' . $value . '%');
                }
            }
        });
    }


    public function store(StoreVirtualReceptionistRequest $request)
    {
        $inputs = $request->validated();

        logger($inputs);

        try {
            // Create a new IVR menu instance
            $ivrMenu = new IvrMenus();

            // Fill the IVR menu with validated input data
            $ivrMenu->fill([
                // 'ivr_menu_uuid' => (string) Str::uuid(), // Generate a unique UUID
                'domain_uuid' => session('domain_uuid'), // Set domain_uuid from session
                'ivr_menu_name' => $inputs['ivr_menu_name'],
                'ivr_menu_extension' => $inputs['ivr_menu_extension'],
                'ivr_menu_enabled' => $inputs['ivr_menu_enabled'] === 'true' ? 'true' : 'false',
                'ivr_menu_digit_len' => $inputs['digit_length'],
                'ivr_menu_timeout' => $inputs['prompt_timeout'],
                'ivr_menu_ringback' => $inputs['ring_back_tone'],
                'ivr_menu_invalid_sound' => $inputs['invalid_input_message'],
                'ivr_menu_max_failures' => $inputs['repeat_prompt'],
                'ivr_menu_direct_dial' => $inputs['direct_dial'] ? 'true' : 'false',
            ]);

            // Save the IVR menu to the database
            $ivrMenu->save();

            // Clear cached destinations session array
            if (isset($_SESSION['destinations']['array'])) {
                unset($_SESSION['destinations']['array']);
            }

            // Return a JSON response indicating success
            return response()->json([
                'messages' => ['success' => ['New item created']]
            ], 201);
        } catch (\Exception $e) {
            // Log the error message
            logger($e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            // report($e);

            // Handle any other exception that may occur
            return response()->json([
                'success' => false,
                'errors' => ['server' => ['Failed to create new item']]
            ], 500);  // 500 Internal Server Error for any other errors
        }
    }

    function update(UpdateVirtualReceptionistRequest $request)
    {
        $inputs = $request->validated();

        try {
            // Retrieve the IVR menu by UUID
            $ivrMenu = IvrMenus::where('ivr_menu_uuid', $inputs['ivr_menu_uuid'])->firstOrFail();

            $exit_data = $this->buildExitDestinationAction($inputs);

            // Update basic IVR menu fields
            $ivrMenu->fill([
                'ivr_menu_name' => $inputs['ivr_menu_name'],
                'ivr_menu_extension' => $inputs['ivr_menu_extension'],
                'ivr_menu_greet_long' => $inputs['ivr_menu_greet_long'] ?? null,
                'ivr_menu_description' => $inputs['ivr_menu_description'] ?? null,
                'ivr_menu_enabled' => $inputs['ivr_menu_enabled'] === 'true' ? 'true' : 'false',
                'ivr_menu_digit_len' => $inputs['digit_length'],
                'ivr_menu_timeout' => $inputs['prompt_timeout'],
                'ivr_menu_ringback' => $inputs['ring_back_tone'],
                'ivr_menu_invalid_sound' => $inputs['invalid_input_message'],
                'ivr_menu_exit_sound' => $inputs['exit_message'],
                'ivr_menu_direct_dial' => $inputs['direct_dial'] ? 'true' : 'false',
                'ivr_menu_max_failures' => $inputs['repeat_prompt'],
                'ivr_menu_exit_app' => $exit_data['action'],
                'ivr_menu_exit_data' => $exit_data['data'],
            ]);

            // Save the updated IVR menu
            $ivrMenu->save();

            //clear the destinations session array
            if (isset($_SESSION['destinations']['array'])) {
                unset($_SESSION['destinations']['array']);
            }

            // Return a JSON response indicating success
            return response()->json([
                'messages' => ['success' => ['Item updated successfully']]
            ], 200);  // 200 OK for successful update
        } catch (\Exception $e) {
            // Log the error message
            logger($e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            // report($e);

            // Handle any other exception that may occur
            return response()->json([
                'success' => false,
                'errors' => ['server' => ['Failed to update item']]
            ], 500);  // 500 Internal Server Error for any other errors
        }
    }

    /**
     * Upload a voicemail greeting.
     *
     * @return \Illuminate\Http\Response
     */
    public function uploadVoicemailGreeting(Request $request, Voicemails $voicemail)
    {

        $domain = Domain::where('domain_uuid', $voicemail->domain_uuid)->first();

        if ($request->greeting_type == "unavailable") {
            $filename = "greeting_1.wav";
            $path = $request->voicemail_unavailable_upload_file->storeAs(
                $domain->domain_name . '/' . $voicemail->voicemail_id,
                $filename,
                'voicemail'
            );
        } elseif ($request->greeting_type == "name") {
            $filename = "recorded_name.wav";
            $path = $request->voicemail_name_upload_file->storeAs(
                $domain->domain_name . '/' . $voicemail->voicemail_id,
                $filename,
                'voicemail'
            );
        }

        if (!Storage::disk('voicemail')->exists($path)) {
            return response()->json([
                'error' => 401,
                'message' => 'Failed to upload file'
            ]);
        }

        // Remove old greeting
        foreach ($voicemail->greetings as $greeting) {
            if ($greeting->filename = $filename) {
                $greeting->delete();
                break;
            }
        }

        if ($request->greeting_type == "unavailable") {
            // Save new greeting in the database
            $greeting = new VoicemailGreetings();
            $greeting->domain_uuid = Session::get('domain_uuid');
            $greeting->voicemail_id = $voicemail->voicemail_id;
            $greeting->greeting_id = 1;
            $greeting->greeting_name = "Greeting 1";
            $greeting->greeting_filename = $filename;
            $voicemail->greetings()->save($greeting);

            // Save default gretting ID
            $voicemail->greeting_id = 1;
            $voicemail->save();
        }

        return response()->json([
            'status' => "success",
            'voicemail' => $voicemail->voicemail_id,
            'filename' => $filename,
            'message' => 'Greeting uploaded successfully'
        ]);
    }


    /**
     * Get voicemail greeting.
     *
     * @return \Illuminate\Http\Response
     */
    public function downloadVoicemailGreeting(Voicemails $voicemail, string $filename)
    {

        $path = session('domain_name') . '/' . $voicemail->voicemail_id . '/' . $filename;

        if (!Storage::disk('voicemail')->exists($path)) abort(404);

        $file = Storage::disk('voicemail')->path($path);
        $type = Storage::disk('voicemail')->mimeType($path);
        $headers = array(
            'Content-Type: ' . $type,
        );

        $response = Response::download($file, $filename, $headers);

        return $response;
    }


    public function getItemOptions()
    {
        try {

            $domain_uuid = request('domain_uuid') ?? session('domain_uuid');
            $item_uuid = request('item_uuid'); // Retrieve item_uuid from the request

            // Base navigation array without Greetings
            $navigation = [
                [
                    'name' => 'Settings',
                    'icon' => 'Cog6ToothIcon',
                    'slug' => 'settings',
                ],
                [
                    'name' => 'Advanced',
                    'icon' => 'AdjustmentsHorizontalIcon',
                    'slug' => 'advanced',
                ],
            ];

            $routingOptionsService = new CallRoutingOptionsService;
            $routingTypes = $routingOptionsService->routingTypes;

            // Only add the Keys tab if item_uuid exists and insert it in the second position
            if ($item_uuid) {
                $greetingsTab = [
                    'name' => 'Keys',
                    'icon' => 'DialpadIcon',
                    'slug' => 'keys',
                ];

                // Insert Greetings tab at the second position (index 1)
                array_splice($navigation, 1, 0, [$greetingsTab]);
            }

            $routes = [
                'get_routing_options' => route('routing.options'),
                'create_key_route' => route('virtual-receptionist.key.create'),
                'update_key_route' => route('virtual-receptionist.key.update'),
                'delete_key_route' => route('virtual-receptionist.key.destroy'),
                'ivr_message_route' => route('ivr.message.url'),
            ];

            // Check if item_uuid exists to find an existing voicemail
            if ($item_uuid) {
                // Find existing ivr by item_uuid
                $ivr = $this->model::with([
                    'options' => function ($query) {
                        $query->select(
                            'ivr_menu_option_uuid',
                            'ivr_menu_uuid',
                            'ivr_menu_option_digits',
                            'ivr_menu_option_action',
                            'ivr_menu_option_param',
                            'ivr_menu_option_order',
                            'ivr_menu_option_description',
                            'ivr_menu_option_enabled',
                        )->orderByRaw('ivr_menu_option_digits::integer');
                    },
                ])->where('ivr_menu_uuid', $item_uuid)->first();

                // If a voicemail exists, use it; otherwise, create a new one
                if (!$ivr) {
                    throw new \Exception("Failed to fetch item details. Item not found");
                }

                // Transform greetings into the desired array format
                $greetingsArray = Recordings::where('domain_uuid', session('domain_uuid'))
                    ->orderBy('recording_name')
                    ->get()
                    ->map(function ($greeting) {
                        return [
                            'value' => $greeting->recording_filename,
                            'name' => $greeting->recording_name,
                        ];
                    })->toArray();

                $routes = array_merge($routes, [
                    'text_to_speech_route' => route('greetings.textToSpeech'),
                    'greeting_route' => route('greeting.url'),
                    'delete_greeting_route' => route('greetings.file.delete'),
                    'update_greeting_route' => route('greetings.file.update'),
                    'upload_greeting_route' => route('greetings.file.upload'),
                    'update_route' => route('virtual-receptionists.update', $ivr),
                    'apply_greeting_route' => route('virtual-receptionist.greeting.apply'),

                ]);
            } else {
                // Create a new voicemail if item_uuid is not provided
                $ivr = $this->model;
                $ivr->ivr_menu_extension = $ivr->generateUniqueSequenceNumber();
                $ivr->ivr_menu_invalid_sound = 'ivr/ivr-that_was_an_invalid_entry.wav';
                $ivr->ivr_menu_ringback = '${us-ring}';
                $ivr->ivr_menu_confirm_attempts = 1;
                $ivr->ivr_menu_timeout = '3000';
                $ivr->ivr_menu_inter_digit_timeout = '2000';
                $ivr->ivr_menu_max_failures = '3';
                $ivr->ivr_menu_max_timeouts = '3';
                $ivr->ivr_menu_digit_len = '5';
                $ivr->ivr_menu_direct_dial = false;
                $ivr->ivr_menu_enabled = 'true';
            }

            $permissions = $this->getUserPermissions();
            // logger($permissions);

            $openAiVoices = [
                ['value' => 'alloy', 'name' => 'Alloy'],
                ['value' => 'echo', 'name' => 'Echo'],
                ['value' => 'fable', 'name' => 'Fable'],
                ['value' => 'onyx', 'name' => 'Onyx'],
                ['value' => 'nova', 'name' => 'Nova'],
                ['value' => 'shimmer', 'name' => 'Shimmer'],
            ];

            $openAiSpeeds = [];

            for ($i = 0.25; $i <= 4.0; $i += 0.25) {
                if (floor($i) == $i) {
                    // Whole number, format with one decimal place
                    $formattedValue = sprintf('%.1f', $i);
                } else {
                    // Fractional number, format with two decimal places
                    $formattedValue = sprintf('%.2f', $i);
                }
                $openAiSpeeds[] = ['value' => $formattedValue, 'name' => $formattedValue];
            }



            // Define the instructions for recording a voicemail greeting using a phone call
            $phoneCallInstructions = [
                'Dial <strong>*732</strong> from your phone.',
                'Enter the virtual receptionist extension number when prompted and press <strong>#</strong>.',
                'Follow the prompts to record your greeting.',
            ];

            $sampleMessage = 'Thank you for calling. For Sales, press 1. For Support, press 2. To repeat this menu, press 9.';

            $promptRepeatOptions = [
                ['value' => '1', 'name' => '1 Time'],
                ['value' => '2', 'name' => '2 Times'],
                ['value' => '3', 'name' => '3 Times'],
                ['value' => '4', 'name' => '4 Times'],
                ['value' => '5', 'name' => '5 Times'],
            ];

            $ring_back_tones = getRingBackTonesCollection(session('domain_uuid'));
            $sounds = getSoundsCollection(session('domain_uuid'));

            // Construct the itemOptions object
            $itemOptions = [
                'navigation' => $navigation,
                'ivr' => $ivr,
                'permissions' => $permissions,
                'greetings' => $greetingsArray ?? null,
                'voices' => $openAiVoices,
                'speeds' => $openAiSpeeds,
                'routes' => $routes,
                'routing_types' => $routingTypes,
                'phone_call_instructions' => $phoneCallInstructions,
                'sample_message' => $sampleMessage,
                'promt_repeat_options' => $promptRepeatOptions,
                'ring_back_tones' => $ring_back_tones,
                'sounds' => $sounds,
                // Define options for other fields as needed
            ];

            return $itemOptions;
        } catch (\Exception $e) {
            // Log the error message
            logger($e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            // report($e);

            // Handle any other exception that may occur
            return response()->json([
                'success' => false,
                'errors' => ['server' => ['Failed to fetch item details']]
            ], 500);  // 500 Internal Server Error for any other errors
        }
    }

    public function getUserPermissions()
    {
        $permissions = [];
        return $permissions;
    }

    public function applyGreeting()
    {
        try {
            // Retrieve the IVR menu by the provided 'ivr' ID
            $ivrMenu = IvrMenus::findOrFail(request('ivr'));

            // Update the 'ivr_menu_greet_long' field with the 'file_name'
            $ivrMenu->ivr_menu_greet_long = request('file_name');

            // Save the changes to the model
            $ivrMenu->save();

            return response()->json([
                'success' => true,
                'messages' => ['success' => ['Your AI-generated greeting has been saved and successfully activated.']]
            ], 200);
        } catch (\Exception $e) {
            // Log the error message
            logger($e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());

            // Handle any other exception that may occur
            return response()->json([
                'success' => false,
                'errors' => ['server' => [$e->getMessage()]]
            ], 500);  // 500 Internal Server Error for any other errors
        }
    }

    public function createKey(CreateVirtualReceptionistKeyRequest $request)
    {
        $inputs = $request->validated();

        try {
            // Create a new IvrMenuOption
            $ivrMenuOption = IvrMenuOptions::create([
                'ivr_menu_option_uuid' => $inputs['option_uuid'] ?? (string) Str::uuid(),
                'ivr_menu_uuid' => $inputs['menu_uuid'],
                'ivr_menu_option_digits' => $inputs['key'],
                'ivr_menu_option_action' => 'menu-exec-app',
                'ivr_menu_option_param' => $this->buildKeyDestinationAction($inputs),
                'ivr_menu_option_description' => $inputs['description'],
                'ivr_menu_option_enabled' => $inputs['status'],
            ]);

            // Return a JSON response indicating success
            return response()->json([
                'messages' => ['success' => ['Virtual Receptionist Key successfully created']],
                'data' => $ivrMenuOption, // Return the created option for confirmation or further use
            ], 201);
        } catch (\Exception $e) {
            logger($e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            // Handle any exception that may occur
            return response()->json([
                'success' => false,
                'errors' => ['server' => ['Unable to create Virtual Receptionist Key.']]
            ], 500);  // 500 Internal Server Error for any other errors
        }
    }


    /**
     * Update Virtual Receptionist Key
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateKey(UpdateVirtualReceptionistKeyRequest $request)
    {

        $inputs = $request->validated();

        try {
            // Find the IvrMenuOption by UUID and Menu UUID
            $ivrMenuOption = IvrMenuOptions::where('ivr_menu_option_uuid', $inputs['option_uuid'])
                ->first();

            if (!$ivrMenuOption) {
                // Handle case where the record is not found
                return response()->json([
                    'success' => false,
                    'errors' => ['server' => ['Virtual Receptionist Key not found.']],
                ], 404); // 404 Not Found
            }

            // Update the attributes
            $ivrMenuOption->update([
                'ivr_menu_option_digits' => $inputs['key'],
                'ivr_menu_option_action' => 'menu-exec-app',
                'ivr_menu_option_param' => $this->buildKeyDestinationAction($inputs),
                'ivr_menu_option_description' => $inputs['description'],
                'ivr_menu_option_enabled' => $inputs['status'],
            ]);

            // Return a JSON response indicating success
            return response()->json([
                'messages' => ['success' => ['Virtual Receptionist Key successfully updated']],
            ], 201);
        } catch (\Exception $e) {
            logger($e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            // Handle any other exception that may occur
            return response()->json([
                'success' => false,
                'errors' => ['server' => ['Unable to update Virtual Receptionist Key.']]
            ], 500);  // 500 Internal Server Error for any other errors
        }
    }

    /**
     * Update Virtual Receptionist Key
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyKey()
    {
        $inputs = request()->all();

        try {
            // Find the IvrMenuOption by UUID
            $ivrMenuOption = IvrMenuOptions::where('ivr_menu_option_uuid', $inputs['ivr_menu_option_uuid'])
                ->first();

            if (!$ivrMenuOption) {
                // Handle case where the record is not found
                return response()->json([
                    'success' => false,
                    'errors' => ['server' => ['Virtual Receptionist Key not found.']],
                ], 404); // 404 Not Found
            }

            // Delete the record
            $ivrMenuOption->delete();

            // Return a JSON response indicating success
            return response()->json([
                'messages' => ['success' => ['Virtual Receptionist Key successfully deleted']],
            ], 200);
        } catch (\Exception $e) {
            logger($e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            // Handle any other exception that may occur
            return response()->json([
                'success' => false,
                'errors' => ['server' => ['Unable to delete Virtual Receptionist Key.']]
            ], 500);  // 500 Internal Server Error for any other errors
        }
    }


    /**
     * Helper function to build destination action based on key action.
     */
    protected function buildKeyDestinationAction($key)
    {
        switch ($key['action']) {
            case 'extensions':
            case 'ring_groups':
            case 'ivrs':
            case 'time_conditions':
            case 'contact_centers':
            case 'faxes':
            case 'call_flows':
                return 'transfer ' . $key['extension'] . ' XML ' . session('domain_name');
            case 'voicemails':
                return 'transfer *99' . $key['extension'] . ' XML ' . session('domain_name');

            case 'recordings':
                // Handle recordings with 'lua' destination app
                return 'lua streamfile.lua ' . $key['extension'];

            case 'check_voicemail':
                return 'transfer *98 XML ' . session('domain_name');

            case 'company_directory':
                return 'transfer *411 XML ' . session('domain_name');

            case 'hangup':
                return 'hangup';

                // Add other cases as necessary for different types
            default:
                return [];
        }
    }

    /**
     * Helper function to build destination action based on exit action.
     */
    protected function buildExitDestinationAction($inputs)
    {
        switch ($inputs['exit_action']) {
            case 'extensions':
            case 'ring_groups':
            case 'ivrs':
            case 'time_conditions':
            case 'contact_centers':
            case 'faxes':
            case 'call_flows':
                return  ['action' => 'transfer', 'data' => $inputs['exit_target_extension'] . ' XML ' . session('domain_name')];
            case 'voicemails':
                return ['action' => 'transfer', 'data' => '*99' . $inputs['exit_target_extension'] . ' XML ' . session('domain_name')];

            case 'recordings':
                // Handle recordings with 'lua' destination app
                return ['action' => 'lua', 'data' => 'streamfile.lua ' . $inputs['exit_target_extension']];

            case 'check_voicemail':
                return ['action' => 'transfer', 'data' => '*98 XML ' . session('domain_name')];

            case 'company_directory':
                return ['action' => 'transfer', 'data' => '*411 XML ' . session('domain_name')];

            case 'hangup':
                return ['action' => 'hangup', 'data' => ''];

                // Add other cases as necessary for different types
            default:
                return [];
        }
    }
}
