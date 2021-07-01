<?php

namespace ppeCore\dvtinh\Http\Controllers;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDOException;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request;
use ppeCore\dvtinh\Http\Requests\AuthRequest;
use ppeCore\dvtinh\Models\User;

class AuthController extends Controller
{
    private $pusher_channel;
    function __construct(){
        $this->pusher_channel = "notLogin". rand(00000, 99999);
    }
    public function register(AuthRequest $request)
    {
        try {
            DB::beginTransaction();
            $req = $request->all();

            $req['password'] = Hash::make($req['password']);
            $user = User::create($req);
            DB::commit();
            $access_token = $user->createToken('authToken')->accessToken;

            return response_api(['user' => $user, 'access_token' => $access_token]);
            throw new Exception(__('ppe.something_wrong'));
        } catch (\PDOException $exception) {
            DB::rollBack();
            throw new PDOException($exception->getMessage());
        } catch (\Exception $exception) {
            DB::rollBack();
            throw new Exception($exception->getMessage());
        }
    }

    public function login(AuthRequest $request)
    {
        $payload = $request->all();
        $user = User::where('email',$payload['email'])->first();
        if ($user){
            if (Hash::check($payload['password'],$user->password)){
                $user->token = $user->createToken('authToken')->accessToken;
                return response()->json([
                    'status'=>true,
                    'data'=>$user
                ]);
            }
        }
        throw new Exception(__('ppe.invalid_credentials'));
//        return response()->json([
//            'status'=>false,
//            'message'=>'username or pass wrongs'
//        ]);
    }
    public function logout(){
        Auth::user()->tokens->each(function($token, $key) {
            $token->delete();
        });
        return response()->json([
            'status'=>true,
            'message'=>'logout success'
        ]);
    }
    public function changePass(Request $request){
        $old_pass = $request["old_password"];
        $new_pass = $request["new_password"];
        $confirm_pass = $request["confirm_password"];

        $user = Auth::user();
        if (Hash::check($old_pass,$user->password)){
            if ($new_pass == $confirm_pass && !Hash::check($new_pass,$user->password)){
                DB::beginTransaction();
                $user->password = Hash::make($new_pass);
                $user->save();
                DB::commit();
                return response_api(['message' => "success"]);
                throw new Exception(__('ppe.something_wrong'));
            }else{
                throw new Exception(__('ppe.something_wrong'));
            }
        }
    }

    public function generateUrl(Request $request)
    {

        $this->pusher_channel = "notLogin". rand(00000, 99999);
        if ($request->platform == 'google') {
            $params = http_build_query([
                'client_id' => config('services.google.client_id'),
                'redirect_uri' => config('services.google.redirect'),
                'scope' => 'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
                'response_type' => 'code',
                'access_type' => 'offline',
                'prompt' => '',
                'state' => json_encode([
                    'platform' => $request->platform,
                    'pusher_channel' => $this->pusher_channel,
                ])
            ]);
            return response()->json([
                'status' => true,
                'data' => [
                    "url"=> "https://accounts.google.com/o/oauth2/v2/auth?{$params}",
                    'pusher_channel' => $this->pusher_channel,
                ]
            ]);
        }
        //---------------------------FACEBOOK-----------------------------
        if ($request->platform=='facebook'){
            $params = http_build_query([
                'client_id' => config('services.facebook.client_id') ,
                'redirect_uri' => config('services.facebook.redirect'),
                'scope'=>'email',
                'response_type'=>'code',
                'auth_type' => 'rerequest',
                'display' =>'popup',
                'state' =>json_encode([
                    'platform'=>$request->platform,
                    'pusher_channel' => $this->pusher_channel,
                ])
            ]);
            return response()->json([
                'status'=>true,
                'data'=>[
                    "url" => "https://www.facebook.com/v9.0/dialog/oauth?{$params}",
                    'pusher_channel' => $this->pusher_channel,
                ]
            ]);
        }
        return response()->json([
            'status' =>   false,
            'message'=> 'something was wrong !',
        ]);
    }


    function authHandle(Request $request)
    {
        $state = json_decode($request->state, true);
        $client = new Client();
        $this->pusher_channel = $state['pusher_channel'];
        if ($state['platform'] == 'google') {
            $data = [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => config('services.google.redirect'),
                'grant_type' => 'authorization_code',
                'code' => $request->code,
            ];
            $res = $client->request('POST', "https://oauth2.googleapis.com/token", [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => $data
            ]);
            $accessToken = json_decode($res->getBody()->getContents(), true);
            $res = $client->request('GET', "https://www.googleapis.com/oauth2/v2/userinfo",
                [
                    'headers' => [
                        'Authorization' => "Bearer {$accessToken['access_token']}",
                    ],
                ]);
            $info = json_decode($res->getBody()->getContents(), true);
            $newUser = [
                'name' => $info['name'],
                'email' => $info['email'],
                'platform' => 'google',
                'access_token_social' => $accessToken['access_token'],
                'first_name' => $info['family_name'],
                'last_name' => $info['given_name'],
                'social_id' => $info['id'],
                'avatar_attachment_id' => $info['picture']
            ];
            $userCreate = User::updateOrCreate([
                'email' => $newUser['email']
            ],
                $newUser);
            $userCreate->access_token = $userCreate->createToken('authToken')->accessToken;
            $res = [
                'status'=>true,
                'data'=>$userCreate,
            ];
            event(new \App\Events\LoginMessage(json_encode($res),$this->pusher_channel));
            return response()->json($res);

        }
        //-------------------------------FACEBOOK------------------------------
        if ($state['platform'] == 'facebook'){
            $res = $client->request('GET',"https://graph.facebook.com/v9.0/oauth/access_token",[
                'query'=>[
                    'client_id' => config('services.facebook.client_id') ,
                    'client_secret' => config('services.facebook.client_secret') ,
                    'redirect_uri' => config('services.facebook.redirect'),
                    'code'=>$request->code,
                ]
            ]);
            $accessToken = json_decode($res->getBody()->getContents(),true);
            $res = $client->request('GET',"https://graph.facebook.com/v9.0/me",
                [
                    'headers'=>[
                        'Authorization'=>"Bearer {$accessToken['access_token']}",
                    ],
                    'query'=>[
                        'fields'=>'id,email,first_name,last_name,picture'
                    ]
                ]);
            $info = json_decode($res->getBody()->getContents(),true);
            $newUser = [
                'email' => @$info['email'],
                'platform' => 'facebook',
                'access_token_social' => $accessToken['access_token'],
                'first_name' => $info['first_name'],
                'name' => $info['last_name'],
                'social_id' =>$info['id'],
                'avatar_attachment_id' => $info['picture']['data']['url']
            ];
            if(empty($newUser['email'])){
                $newUser['email'] = $newUser['platform'].'.'.$newUser['social_id'];
            }
            $userCreate = User::updateOrCreate([
                'email' => $newUser['email']
            ],
                $newUser);
            $userCreate->access_token = $userCreate->createToken('authToken')->accessToken;
            $res = [
                'status'=>true,
                'data'=>$userCreate,
            ];
            event(new \App\Events\LoginMessage(json_encode($res),$this->pusher_channel));
            return response()->json($res);
        }
    }



}
