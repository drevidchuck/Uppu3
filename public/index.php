<?php
require '../vendor/autoload.php';
require_once '../app/bootstrap.php';
use Uppu3\Helper\FormatHelper;
use Uppu3\Helper\CommentHelper;
use Uppu3\Helper\HashGenerator;
use Uppu3\Helper\LoginHelper;

$app = new \Slim\Slim(array('view' => new \Slim\Views\Twig(), 'templates.path' => '../app/templates'));

$app->container->singleton('em', function () use ($entityManager) {

    return $entityManager;
});

$app->container->singleton('loginHelper', function () use ($app) {
    return new LoginHelper($app->em);
});

$app->container->singleton('currentUser', function () use ($app) {
    $helper = new LoginHelper($app->em);
    return $helper->getCurrentUser();
});
$app->view->appendData(array(
    'loginHelper' => $app->loginHelper,
    'currentUser' => $app->currentUser,
    'message' => ''
));

function checkAuthorization()
{
    $app = \Slim\Slim::getInstance();
    $isLogged = $app->loginHelper;
    if ($isLogged->logged != true) {
        $app->redirect($app->urlFor('login', array('from' => 'josh')));
    }
}

;
$app->map('/', function () use ($app) {
    $page = 'index';
    if ($app->request->isGet()) {
        $app->render('file_load.html', array('page' => $page));
        $app->stop();
    }
    if (file_exists($_FILES['load']['tmp_name']) && $_FILES['load']['error'] == 0) {
        $cookie = $app->getCookie('salt');
        if (!$cookie) {
            $cookie = HashGenerator::generateSalt();
            $app->setCookie('salt', $cookie, '1 month');
        }
        $user = $app->em->getRepository('Uppu3\Entity\User')->findOneBy(array('salt' => $cookie));
        if (!$user) {
            $user = \Uppu3\Helper\UserHelper::saveAnonymousUser($cookie, $app->em);
        }
        $fileHelper = new \Uppu3\Helper\FileHelper($user, $app->em);
        $file = $fileHelper->fileSave($_FILES);
        $id = $file->getId();
        $app->redirect("/view/$id");
    } else {
        $message = "You didn't select any file";
        $app->render('file_load.html', array('page' => $page, 'message' => $message));
    }
})->via('GET', 'POST');

$app->map('/login', function () use ($app) {
    if ($app->request->isGet()) {
        $page = 'login';
        $flash = '';
        $app->render('login_form.html', array('page' => $page, 'flash' => $flash));
    } else {
        if ($user = $app->em->getRepository('Uppu3\Entity\User')
            ->findOneBy(array('login' => $app->request->params('login')))
        ) {
            if ($user->getHash() === HashGenerator::generateHash($app->request->params('password'), $user->getSalt())) {
                $id = $user->getId();
                $app->loginHelper->authenticateUser($user);
                $app->redirect("users/$id");
            } else {
                $error = "Invalid login or password";
                $app->render('login_form.html', array('message' => $error, 'data' => $_POST));
                return;
            }

        }
        $error = "Invalid login or password";
        $app->render('login_form.html', array('message' => $error, 'data' => $_POST));
    }
})->via('GET', 'POST')->name('login');

$app->get('/logout', function () use ($app) {
    $app->loginHelper->logout();
    $app->redirect('/');
});

$app->map('/register', function () use ($app) {
    if ($app->request->isGet()) {
        $app->render('register.html');
    } else {
        $cookie = $app->getCookie('salt');
        if (!$cookie) {
            $cookie = HashGenerator::generateSalt();
            $app->setCookie('salt', $cookie, '1 month');
        }
        $validation = new \Uppu3\Helper\DataValidator;
        $userHelper = new \Uppu3\Helper\UserHelper($_POST, $app->em, $cookie);
        $user = $userHelper->user;
        $validation->validateUser($user, $_POST);
        if (!$validation->hasErrors()) {
            $userHelper->userSave($app->request->params('password'), $cookie, $app->em);
            $id = $userHelper->user->getId();
            $app->loginHelper->authenticateUser($userHelper->user);
            $app->redirect("users/$id");
        } else {
            $app->render('register.html', array('errors' => $validation->error, 'data' => $_POST));
        };
    }
})->via('GET', 'POST');


$app->get('/view/:id/', function ($id) use ($app) {
    $file = $app->em->find('Uppu3\Entity\File', $id);
    $user = $app->em->getRepository('Uppu3\Entity\User')->findOneById($file->getUploadedBy());
    if (!$file) {
        $app->notFound();
    }
    $helper = new FormatHelper();
    $comments = $app->em->getRepository('Uppu3\Entity\Comment')->findBy(array('fileId' => $id), array('path' => 'ASC'));
    $app->render('view.html', array('file' => $file, 'user' => $user, 'helper' => $helper, 'comments' => $comments));
});

$app->get('/comment/:id/', function ($id) use ($app) {
    $comment = $app->em->find('Uppu3\Entity\Comment', $id);
    echo $comment->getComment();
});

$app->get('/download/:id/:name', function ($id, $name) use ($app) {
    $file = $app->em->find('Uppu3\Entity\File', $id);
    $name = FormatHelper::formatDownloadFile($id, $file->getName());

    if (file_exists($name)) {
        header("X-Sendfile:" . realpath(dirname(__FILE__)) . '/' . $name);
        header("Content-Type: application/octet-stream");
        header("Content-Disposition: attachment");
        return;
    } else {
        $app->notFound();
    }
});

$app->get('/users/', 'checkAuthorization', function () use ($app) {
    $cookie = $app->getCookie('salt');
    $page = 'users';
    $users = $app->em->getRepository('Uppu3\Entity\User')->findBy([], ['created' => 'DESC']);
    $filesCount = [];
    foreach ($users as $user) {
        $filesCount[$user->getId()] = count($app->em->getRepository('Uppu3\Entity\File')
            ->findByUploadedBy($user->getId()));
    }
    $app->render('users.html', array('users' => $users, 'page' => $page, 'cookie' => $cookie, 'filesCount' => $filesCount));
});

$app->get('/users/:id/', function ($id) use ($app) {
    $helper = new FormatHelper();
    $page = 'users';
    $user = $app->em->getRepository('Uppu3\Entity\User')->findOneById($id);
    $files = $app->em->getRepository('Uppu3\Entity\File')->findByUploadedBy($id);
    $app->render('user.html', array('user' => $user, 'files' => $files, 'helper' => $helper, 'page' => $page));
});

$app->get('/list', 'checkAuthorization', function () use ($app) {
    $helper = new FormatHelper();
    $page = 'list';
    $files = $app->em->getRepository('Uppu3\Entity\File')->findBy([], ['uploaded' => 'DESC'], 50, 0);
    $app->render('list.html', array('files' => $files, 'page' => $page, 'helper' => $helper));
});

$app->post('/send/:id', function ($id) use ($app) {
    $parent = isset($_POST['parent']) ? $app->em->find('Uppu3\Entity\Comment', $app->request->params('parent')) : null;
    $file = $app->em->find('Uppu3\Entity\File', $id);
    $user = $app->em->find('Uppu3\Entity\User', $app->request->params('userId'));
    $validation = new \Uppu3\Helper\DataValidator;
    $commentHelper = new CommentHelper($_POST, $app->em, $parent, $file, $user);
    $comment = $commentHelper->comment;
    $validation->validateComment($comment);
    if (!$validation->hasErrors()) {
      $commentHelper->commentSave();
    };
    $comments = $app->em->getRepository('Uppu3\Entity\Comment')->findBy(array('fileId' => $id), array('path' => 'ASC'));
    $app->render('comments.html', array('comments' => $comments));
});

$app->post('/ajaxComments/:id', function ($id) use ($app) {
    $comments = $app->em->getRepository('Uppu3\Entity\Comment')->findBy(array('fileId' => $id), array('path' => 'ASC'));
    $app->render('comments.html', array('comments' => $comments));
});


$app->run();
