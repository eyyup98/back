<?php


namespace app\api\modules\v1\base;


use app\api\modules\v1\helpers\ExceptionHelper;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Module;
use yii\filters\Cors;
use yii\rest\ActiveController;
use yii\web\BadRequestHttpException;
use yii\web\HttpException;
use yii\web\Response;

class BaseApiController extends ActiveController
{
    public int $user_id;
    public int $user_shop_id;

    /**
     * @throws HttpException
     */
    public function __construct($id, $module, $config = [])
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->charset = 'UTF-8';

//        $this->checkToken();

        parent::__construct($id, $module, $config);
    }

    public function actions(): array
    {
        return $this->deleteAction(['index', 'create', 'update', 'delete']);
    }

    /**
     * Без этого метода происходит 404 Not Found
     */
    public function actionIndex()
    {}

    public function deleteAction(array $actions): array
    {
        $parentActions = parent::actions();
        foreach ($actions as $action) {
            unset($parentActions[$action]);
        }

        return $parentActions;
    }

    /**
     * Runs an action within this controller with the specified action ID and parameters.
     * If the action ID is empty, the method will use [[defaultAction]].
     * @param string $id the ID of the action to be executed.
     * @param array $params the parameters (name-value pairs) to be passed to the action.
     * @return mixed the result of the action.
     * @throws BadRequestHttpException
     * @throws InvalidConfigException
     * @throws HttpException
     * @see createAction()
     */
    public function runAction($id, $params = [])
    {
        $action = $this->createAction($id);

        if ($action === null) {
            if (!ctype_digit($id)) {
                throw new HttpException(404, "404 Not Found. Page '$id' not found.", 404);
            }
        }

        if ($id == '' || ctype_digit($id)) {
            $params['id'] = $id;

            $action = match (Yii::$app->request->method) {
                'GET' => $this->createAction('index'),
                'POST' => $this->createAction('create'),
                'PUT',
                'PATCH' => $this->createAction('update'),
                'DELETE' => $this->createAction('delete')
            };
        }

        Yii::debug('Route to run: ' . $action->getUniqueId(), __METHOD__);

        if (Yii::$app->requestedAction === null) {
            Yii::$app->requestedAction = $action;
        }

        $oldAction = $this->action;
        $this->action = $action;

        $modules = [];
        $runAction = true;

        foreach ($this->getModules() as $module) {
            if ($module->beforeAction($action)) {
                array_unshift($modules, $module);
            } else {
                $runAction = false;
                break;
            }
        }

        $result = null;

        if ($runAction && $this->beforeAction($action)) {
            $result = $action->runWithParams($params);

            $result = $this->afterAction($action, $result);

            foreach ($modules as $module) {
                /* @var $module Module */
                $result = $module->afterAction($action, $result);
            }
        }

        if ($oldAction !== null) {
            $this->action = $oldAction;
        }

        return $result;
    }

    /**
     * @throws HttpException
     */
    protected function checkToken()
    {
        if (
            Yii::$app->request->getMethod() == 'GET' ||
            Yii::$app->request->getMethod() == 'DELETE'
        ) {
            $json = Yii::$app->request->get();
        } else {
            $json = $this->postQueryParams();
        }

        try {

            $auth = [
                'action' => 'checkToken',
                'token' => $json['token'],
                'shop_id' => $json['shop_id']
            ];

            $client = new BaseClient();
            $res = $client->request(
                "POST",
                require __DIR__ . "/../../../../config/authTokenUrl.php",
                [
                    'body' => json_encode($auth)
                ]
            );
            $content = json_decode((string)$res->getBody(), true);
        } catch (Exception) {}

        if (empty($content['user_id']) || empty($content['user_shop_id'])) {
            throw new HttpException(401, '401 Unauthorized', 401);
        }

        $this->user_id = $content['user_id'];
        $this->user_shop_id = $content['user_shop_id'];
    }

    private function postQueryParams()
    {
        $pathInfo = Yii::$app->getRequest()->pathInfo;
        $pathInfo = explode('/', $pathInfo);

        if (!empty($pathInfo[2]) && $pathInfo[2] == 'uploaded') {
            return Yii::$app->request->get();
        } else {
            return json_decode(file_get_contents('php://input'), true);
        }
    }

    public static function createResponse($statusCode = 200, string $content = '')
    {
        $response = Yii::$app->response;
        $response->statusCode = $statusCode >= 200 && $statusCode < 600 ? $statusCode : 500;
        $response->content = json_encode(['msg' => $content]);
        $response->format = Response::FORMAT_JSON;

        return $response;
    }

    public function __destruct()
    {
        ExceptionHelper::checkException();
    }

    #[ArrayShape(['corsFilter' => "array"])]
    public function behaviors()
    {
        return [
            'corsFilter' => [
                'class' => Cors::class,
                'cors' => [
                    'Origin' => ['*'],
                    'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                    'Access-Control-Request-Headers' => ['*'],
                    'Access-Control-Allow-Credentials' => null,
                    'Access-Control-Max-Age' => 3600,
                    'Access-Control-Expose-Headers' => []
                ]
            ]
        ];
    }
}