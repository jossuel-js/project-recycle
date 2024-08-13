<?php
require_once __DIR__ . '/../../models/twigconfig.php';
require_once __DIR__ . '/../../vendor/autoload.php';

require_once __DIR__ . '../../controllers/pessoa/controller_pessoa.php';
require_once __DIR__ . '../../controllers/anuncio/controller_anuncio.php';
require_once __DIR__ . '../../controllers/sessao/controller_session.php';


use Pecee\SimpleRouter\SimpleRouter;
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // Erro sessao já estpa iniciada php superglobals modify globasl name
}



$twig = configurarTwig();
$pdo = conectarBanco();


// -------------------------------------------------------- Rotas ----------------------------------------------------------------
SimpleRouter::get('/', function() {
    header('Location: /login');
    exit;
});

SimpleRouter::get('/login', function() use ($twig) {
    echo $twig->render('login.twig');
});

SimpleRouter::post('/login', function() use ($twig, $pdo) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

   
    $stmt = $pdo->prepare('SELECT id, senha FROM recycle.tb_users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['senha'])) {
         
        $_SESSION['logged_in'] = true;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = $user['id'];     
        header('Location: /home');
        exit;
    }

    echo $twig->render('login.twig', ['error' => 'Credenciais inválidas', 'logged_in' => checkLogin()]);
});

SimpleRouter::get('/register', function() use ($twig) {
    echo $twig->render('register.twig');
});

SimpleRouter::post('/register', function() use ($twig, $pdo) {
    $nome = $_POST['nome'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // bcrypt da senha
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare('INSERT INTO recycle.tb_users (email, senha, nome) VALUES (:email, :senha, :nome)');
    $stmt->execute([
        'email' => $email,
        'senha' => $hashedPassword,
        'nome' => $nome
    ]);

    echo $twig->render('register.twig', ['success' => 'Usuário registrado com sucesso', 'logged_in' => checkLogin()]);
});



SimpleRouter::get('/home', function() use ($twig, $pdo) {

    $logged_in = checkLogin();
    if (!$logged_in) {
        header('Location: /login');
        exit;
    }
    
    $user_id = $_SESSION['user_id'] ?? null;

    $limit = 6; 

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) {
        $page = 1; 
    }

    $offset = ($page - 1) * $limit;

    $announcements = getAllAnnouncements($limit, $offset);
    $totalCount = getTotalAnnouncementsCount();
    $totalPages = ceil($totalCount / $limit);

    if ($page > $totalPages && $totalPages > 0) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
        $announcements = getAllAnnouncements($limit, $offset);
    }

    echo $twig->render('home.twig', [
        'logged_in' => $logged_in,
        'user_id' => $user_id, 
        'announcements' => $announcements,
        'current_page' => $page,
        'total_pages' => $totalPages
    ]);
});




SimpleRouter::get('/meus-anuncios', function() use ($twig, $pdo) {
    if (!checkLogin()) {
        header('Location: /login');
        exit;
    }

    $user_id = $_SESSION['user_id'] ?? null;
    $limit = 6; 

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) {
        $page = 1; 
    }

    $offset = ($page - 1) * $limit;

    $announcements = getUserAnnouncements($user_id, $limit, $offset);
    $totalCount = getTotalAnnouncementsCount($user_id);
    $totalPages = ceil($totalCount / $limit);

    if ($page > $totalPages && $totalPages > 0) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
        $announcements = getUserAnnouncements($user_id, $limit, $offset);
    }

    echo $twig->render('anuncios/meus_anuncios.twig', [
        'user_id' => $user_id,
        'announcements' => $announcements,
        'current_page' => $page,
        'logged_in' => checkLogin(),
        'total_pages' => $totalPages
    ]);
});

SimpleRouter::get('/create-anuncio', function() use ($twig) {
    if (!checkLogin()) {
        header('Location: /login');
        exit;
    }
    $user_id = $_SESSION['user_id'] ?? null;
    echo $twig->render('anuncios/create_anuncio.twig', ['logged_in' => checkLogin(),'user_id' => $user_id]);
});
SimpleRouter::post('/create-anuncio', function() use ($twig, $pdo) {
    if (!checkLogin()) {
        header('Location: /login');
        exit;
    }
    $user = getUser();

    $assunto = $_POST['assunto'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $preco = $_POST['preco'] ?? '';
    $imagem = isset($_FILES['imagem']) && $_FILES['imagem']['tmp_name'] ? file_get_contents($_FILES['imagem']['tmp_name']) : null;

    $stmt = $pdo->prepare('
        INSERT INTO recycle.tb_anuncios (user_id, assunto, descricao, preco, imagem) 
        VALUES (:user_id, :assunto, :descricao, :preco, :imagem)
    ');

    $stmt->bindParam(':user_id', $user['id'], PDO::PARAM_INT);
    $stmt->bindParam(':assunto', $assunto, PDO::PARAM_STR);
    $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
    $stmt->bindParam(':preco', $preco, PDO::PARAM_STR);
    $stmt->bindParam(':imagem', $imagem, PDO::PARAM_LOB);

    $stmt->execute();

    $_SESSION['success'] = 'Anúncio criado com sucesso';

    header('Location: /meus-anuncios');
    exit;
});
SimpleRouter::post('/edit-anuncio/{id}', function($id) use ($twig, $pdo) {
    if (!checkLogin()) {
        header('Location: /login');
        exit;
    }

    // Verifique se o anúncio pertence ao usuário atual
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare('SELECT user_id FROM recycle.tb_anuncios WHERE id = :id');
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $owner = $stmt->fetchColumn();

    if ($owner !== $user_id) {
        header('Location: /meus-anuncios');
        exit;
    }

    // Dados do formulário
    $assunto = $_POST['assunto'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $preco = $_POST['preco'] ?? '';

    // Verifica se uma nova imagem foi enviada e a lê
    $imagem = null;
    if (isset($_FILES['imagem']) && $_FILES['imagem']['tmp_name']) {
        $imagem = file_get_contents($_FILES['imagem']['tmp_name']);
    }

    // Atualiza o anúncio
    $stmt = $pdo->prepare('
        UPDATE recycle.tb_anuncios 
        SET assunto = :assunto, descricao = :descricao, preco = :preco, imagem = :imagem 
        WHERE id = :id AND user_id = :user_id
    ');

    $stmt->bindParam(':assunto', $assunto, PDO::PARAM_STR);
    $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
    $stmt->bindParam(':preco', $preco, PDO::PARAM_STR);
    $stmt->bindParam(':imagem', $imagem, PDO::PARAM_LOB);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);

    $stmt->execute();

    $_SESSION['success'] = 'Anúncio atualizado com sucesso';

    header('Location: /meus-anuncios');
    exit;
});

SimpleRouter::get('/edit-anuncio/{id}', function($id) use ($twig, $pdo) {
    if (!checkLogin()) {
        header('Location: /login');
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM recycle.tb_anuncios WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $id, 'user_id' => $_SESSION['user_id']]);
    $anuncio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$anuncio) {
        header('Location: /meus-anuncios');
        exit;
    }

    // Codifica a imagem em base64 se ela existir
    if ($anuncio['imagem']) {
        $imagemData = stream_get_contents($anuncio['imagem']);
        $anuncio['imagem'] = 'data:image/jpeg;base64,' . base64_encode($imagemData);
    }

    $user_id = $_SESSION['user_id'] ?? null;
    echo $twig->render('anuncios/edit_anuncio.twig', ['anuncio' => $anuncio, 'user_id' => $user_id, 'logged_in' => checkLogin()]);
});

SimpleRouter::post('/edit-anuncio/{id}', function($id) use ($twig, $pdo) {
    if (!checkLogin()) {
        header('Location: /login');
        exit;
    }

    $assunto = $_POST['assunto'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $preco = $_POST['preco'] ?? '';
    
    // Verifica se uma nova imagem foi enviada e a lê
    $imagem = null;
    if (isset($_FILES['imagem']) && $_FILES['imagem']['tmp_name']) {
        $imagem = file_get_contents($_FILES['imagem']['tmp_name']);
        // Aqui você pode verificar o tipo e o conteúdo da imagem, se necessário
    }

    $stmt = $pdo->prepare('UPDATE recycle.tb_anuncios SET assunto = :assunto, descricao = :descricao, preco = :preco, imagem = :imagem WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'assunto' => $assunto,
        'descricao' => $descricao,
        'preco' => $preco,
        'imagem' => $imagem,
        'id' => $id,
        'user_id' => $_SESSION['user_id']
    ]);

    $_SESSION['success'] = 'Anúncio atualizado com sucesso';

    header('Location: /meus-anuncios');
    exit;
});

SimpleRouter::get('/delete-anuncio/{id}', function($id) use ($pdo) {
    if (!checkLogin()) {
        header('Location: /login');
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM recycle.tb_anuncios WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $id, 'user_id' => $_SESSION['user_id']]);

    $_SESSION['success'] = 'Anúncio excluído com sucesso';

    header('Location: /meus-anuncios');
    exit;
});

SimpleRouter::get('/image/{id}', function($id) use ($pdo) {
    $stmt = $pdo->prepare('SELECT imagem FROM recycle.tb_anuncios WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $imageData = $stmt->fetchColumn();

    if ($imageData) {
        header('Content-Type: image/jpeg');
        echo $imageData;
        exit;
    } else {
        http_response_code(404);
        echo 'Imagem não encontrada.';
        exit;
    }
});


SimpleRouter::get('/profile/{id}', function ($id) use ($twig, $pdo) {
    
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    // Mensagens de erro e sucesso
    $error = $_SESSION['error'] ?? null;
    $success = $_SESSION['success'] ?? null;
    unset($_SESSION['error'], $_SESSION['success']);

    $user_id = $_SESSION['user_id'];

    // Encontrar o usuário pelo ID passado na URL
    $user = findUserById($id, $pdo);

    if (!$user) {
        echo "Usuário não encontrado.";
        exit();
    }

    // Renderizar o template com as informações do usuário e mensagens de erro/sucesso
    echo $twig->render('profile/view-profile.twig', [
        'user' => $user,
        'user_id' => $user_id,
        'error' => $error,
        'success' => $success,
        'logged_in' => checkLogin()
    ]);
});


SimpleRouter::get('/edit/profile', function () use ($twig, $pdo) {
   
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    $error = $_SESSION['error'] ?? null;
    $success = $_SESSION['success'] ?? null;
    unset($_SESSION['error'], $_SESSION['success']);

    $user_id = $_SESSION['user_id'];

    
    $user = findUserById($user_id, $pdo);

    if (!$user) {
        
        echo "Não foi possível encontrar o perfil do usuário. Por favor, verifique se o usuário ainda existe ou entre em contato com o suporte.";
        exit();
    }

    
    echo $twig->render('profile/edit-profile.twig', [
        'user' => $user,
        'logged_in' => checkLogin(),
        'user_id' => $user_id,
        'error' => $error,
        'success' => $success
    ]);
});


SimpleRouter::post('/profile_update', function () use ($pdo) {
   
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    
    $id = $_SESSION['user_id'];

    
    $nome = $_POST['nome'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $localizacao = $_POST['localizacao'] ?? '';
    

    
    $stmt = $pdo->prepare('UPDATE recycle.tb_users SET nome = :nome, telefone = :telefone , localizacao = :localizacao  WHERE id = :id');
    $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
    $stmt->bindParam(':localizacao', $localizacao, PDO::PARAM_STR);
    $stmt->bindParam(':telefone', $telefone, PDO::PARAM_STR);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);

    $success = $stmt->execute();

    if ($success) {
        $_SESSION['success'] = 'Perfil atualizado com sucesso';
    } else {
        $_SESSION['error'] = 'Erro ao atualizar perfil';
    }

    header('Location: /profile/' . $id);
    exit();
});


SimpleRouter::post('/like/profile/{id}', function($id) {
    likeProfile($id);
    
});

SimpleRouter::post('/like-announcement/{id}', function($id) {
    likeAnnouncement($id);
});


SimpleRouter::get('/logout', function() {
    session_start();
    session_unset();
    session_destroy();
    header('Location: /login');
    exit;
});

SimpleRouter::start();
?>