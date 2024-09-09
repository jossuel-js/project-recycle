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

SimpleRouter::get('/registrar-se', function() use ($twig) {
    echo $twig->render('register.twig');
});

SimpleRouter::post('/registrar-se', function() use ($twig, $pdo) {
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
    $search_term = isset($_GET['search']) ? $_GET['search'] : ''; // Captura o termo de busca
    
    if ($page < 1) {
        $page = 1; 
    }

    $offset = ($page - 1) * $limit;

    // Obtenha todos os anúncios paginados com o termo de busca
    $announcements = getAllAnnouncements($limit, $offset, $search_term);

    // Ajusta a contagem total de anúncios com base no termo de busca
    $totalCount = getTotalAnnouncementsCount($search_term);
    $totalPages = ceil($totalCount / $limit);

    if ($page > $totalPages && $totalPages > 0) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
        $announcements = getAllAnnouncements($limit, $offset, $search_term);
    }

    // Adiciona a contagem de curtidas e status de "curtido" para cada anúncio
    foreach ($announcements as &$announcement) {
        $announcement_details = getAnnouncementDetails($announcement['id'], $user_id);
        $announcement['likes_count'] = $announcement_details['like_count'];
        $announcement['user_liked'] = $announcement_details['user_liked'];
    }

    echo $twig->render('home.twig', [
        'logged_in' => $logged_in,
        'user_id' => $user_id, 
        'announcements' => $announcements,
        'current_page' => $page,
        'total_pages' => $totalPages,
        'isAdmin' =>isAdmin(),
        'session' => $_SESSION, // Passa as mensagens da sessão para o Twig
        'search_term' => htmlspecialchars($search_term) // Passa o termo de busca para o Twig

    ]);

    // Limpa as mensagens de sucesso/erro após exibir
    unset($_SESSION['success'], $_SESSION['error']);
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
SimpleRouter::post('/ocultar-anuncio/{id}', function ($id) use ($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    // Obter o ID do usuário logado
    $user_id = $_SESSION['user_id'];

    // Verificar se o anúncio pertence ao usuário logado
    $stmt = $pdo->prepare("SELECT user_id, ativo FROM recycle.tb_anuncios WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($announcement && $announcement['user_id'] === $user_id) {
        // Alternar o status do anúncio
        $new_status = !$announcement['ativo'];
        $stmt = $pdo->prepare("UPDATE recycle.tb_anuncios SET ativo = :ativo WHERE id = :id");
        $stmt->bindParam(':ativo', $new_status, PDO::PARAM_BOOL);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    // Redirecionar de volta para a página de anúncios
    header('Location: /meus-anuncios');
    exit();
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


SimpleRouter::get('/perfil/{id}', function ($id) use ($twig, $pdo) {
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

    // Contar os likes do usuário
    $totalLikes = countUserLikes($id, $pdo);

    // Verificar se o usuário atual já curtiu o perfil
    $userCurtido = hasUserLikedProfile($user_id, $id, $pdo);

    // Encontrar os anúncios do usuário
    $announcements = findUserAnnouncements($id, $pdo);

    // Renderizar o template com as informações do usuário e mensagens de erro/sucesso
    echo $twig->render('profile/view-profile.twig', [
        'user' => $user,
        'user_id' => $user_id,
        'total_likes' => $totalLikes,
        'user_has_liked' => $userCurtido,
        'error' => $error,
        'success' => $success,
        'logged_in' => checkLogin(),
        'isAdmin' =>isAdmin(),
        'announcements' => $announcements
    ]);
});





SimpleRouter::get('/editar/perfil', function () use ($twig, $pdo) {
   
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
    $ramo_mercado = $_POST['ramomercado'] ?? '';
    $descricao_adicional = $_POST['descricao_adicional'] ?? '';

    // Atualiza informações básicas do perfil
    $stmt = $pdo->prepare('UPDATE recycle.tb_users SET nome = :nome, telefone = :telefone, localizacao = :localizacao, ramo_mercado = :ramo_mercado, descricao_adicional = :descricao_adicional WHERE id = :id');
    $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
    $stmt->bindParam(':telefone', $telefone, PDO::PARAM_STR);
    $stmt->bindParam(':localizacao', $localizacao, PDO::PARAM_STR);
    $stmt->bindParam(':ramo_mercado', $ramo_mercado, PDO::PARAM_STR);
    $stmt->bindParam(':descricao_adicional', $descricao_adicional, PDO::PARAM_STR);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);

    $success = $stmt->execute();

    if ($success) {
        // Processa o upload da imagem
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $image = file_get_contents($_FILES['foto']['tmp_name']);
            $stmt = $pdo->prepare('UPDATE recycle.tb_users SET foto = :foto WHERE id = :id');
            $stmt->bindParam(':foto', $image, PDO::PARAM_LOB);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        }
        $_SESSION['success'] = 'Perfil atualizado com sucesso';
    } else {
        $_SESSION['error'] = 'Erro ao atualizar perfil';
    }

    header('Location: /perfil/' . $id);
    exit();
});




SimpleRouter::post('/like/profile/{id}', function($id) {
    likeProfile($id);
    
});
SimpleRouter::post('/unlike/profile/{id}', function ($id) use ($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    $currentUserId = $_SESSION['user_id'];

    // Remover o like do perfil
    try {
        $stmt = $pdo->prepare("
            DELETE FROM recycle.profile_likes
            WHERE user_id = :user_id AND liked_user_id = :liked_user_id
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':liked_user_id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $_SESSION['success'] = "Você descurtiu o perfil com sucesso.";
    } catch (PDOException $e) {
        error_log('Error in unlikeProfile: ' . $e->getMessage());
        $_SESSION['error'] = "Erro ao descurtir o perfil.";
    }

    header('Location: /perfil/' . $id);
    exit();
});


SimpleRouter::post('/like-announcement/{id}', function($id) {
    likeAnnouncement($id);
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit();
});



SimpleRouter::get('/curtidas/anuncios', function () use ($twig, $pdo) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    $userId = $_SESSION['user_id'];

    // Obter os anúncios curtidos e converter imagens para base64
    $announcements = findLikedAnnouncements($userId, $pdo);

    // Renderizar o template com os anúncios curtidos
    echo $twig->render('anuncios/liked-announcements.twig', [
        'announcements' => $announcements,
        'logged_in' => checkLogin(),
        'user_id' => $userId
        
    ]);
});




SimpleRouter::get('/anuncio/{id}/denuncias', function ($id) use ($twig, $pdo) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    // Obtemos as denúncias do anúncio específico no banco de dados
    $stmt = $pdo->prepare('SELECT * FROM recycle.tb_denuncias WHERE anuncio_id = :anuncio_id ORDER BY criado_em DESC');
    $stmt->bindParam(':anuncio_id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $denuncias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo $twig->render('denuncia/denuncias.twig', ['denuncias' => $denuncias, 'anuncio_id' => $id,'logged_in' => checkLogin(),'isAdmin' =>isAdmin()]);
});


// Exibir o formulário de denúncia
SimpleRouter::get('/anuncio/{id}/denuncia', function ($id) use ($twig) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    // Renderiza o formulário de denúncia
    echo $twig->render('denuncia/form.twig', ['anuncio_id' => $id,'isAdmin' =>isAdmin(), 'logged_in' => checkLogin()]);
});

// Processar a denúncia submetida
// Processar a denúncia submetida
SimpleRouter::post('/anuncio/{id}/denuncia', function ($id) use ($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    // Recebe os dados do formulário
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $user_id = $_SESSION['user_id'];

    // Insere a nova denúncia no banco de dados
    $stmt = $pdo->prepare('INSERT INTO recycle.tb_denuncias (anuncio_id, titulo, descricao, criado_em, atualizado_em) VALUES (:anuncio_id, :titulo, :descricao, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
    $stmt->bindParam(':anuncio_id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':titulo', $titulo, PDO::PARAM_STR);
    $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
    $success = $stmt->execute();

    if ($success) {
        $_SESSION['success'] = 'Denúncia registrada com sucesso';
    } else {
        $_SESSION['error'] = 'Erro ao registrar denúncia';
    }

    // Redireciona para a página inicial
    header('Location: /home');
    exit();
});




SimpleRouter::post('/denuncia/{id}/update', function ($id) use ($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    // Recebe os dados do formulário
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';

    // Atualiza a denúncia no banco de dados
    $stmt = $pdo->prepare('UPDATE recycle.tb_denuncias SET titulo = :titulo, descricao = :descricao, atualizado_em = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':titulo', $titulo, PDO::PARAM_STR);
    $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
 
    $success = $stmt->execute();

    if ($success) {
        $_SESSION['success'] = 'Denúncia atualizada com sucesso';
    } else {
        $_SESSION['error'] = 'Erro ao atualizar denúncia';
    }

    header('Location: /anuncio/' . $id . '/denuncias');
    exit();
});







SimpleRouter::get('/logout', function() {
    session_start();
    session_unset();
    session_destroy();
    header('Location: /login');
    exit;
});

SimpleRouter::get('/admin-dashboard', function () use ($pdo, $twig) {
    // Executa a consulta para buscar os anúncios com denúncias
    $stmt = $pdo->prepare("
    SELECT 
        a.id AS anuncio_id, 
        a.assunto, 
        a.descricao, 
        a.preco, 
        a.data_criacao,
        a.imagem,  -- Inclua a coluna imagem aqui
        d.id AS denuncia_id,
        d.titulo AS denuncia_titulo,
        d.descricao
    FROM recycle.tb_anuncios a
    INNER JOIN recycle.tb_denuncias d ON a.id = d.anuncio_id
    ORDER BY d.criado_em DESC
    ");
    $stmt->execute();
    $denuncias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Processa as imagens para base64
    foreach ($denuncias as &$denuncia) {
        if ($denuncia['imagem']) {
            // Converte o recurso de fluxo em uma string e depois para base64
            $imagemData = stream_get_contents($denuncia['imagem']);
            $denuncia['imagem'] = base64_encode($imagemData);
        }
    }

    // Renderiza a view admin_dashboard.twig com os dados das denúncias
    echo $twig->render('admin/admin_dashboard.twig', [
        'denuncias' => $denuncias,
        'isAdmin' => isAdmin(),
        'logged_in' => checkLogin()
    ]);
});


SimpleRouter::post('/admin/excluir-anuncio', function () use ($pdo) {
    if (isset($_POST['anuncio_id']) && !empty($_POST['anuncio_id'])) {
        $anuncio_id = $_POST['anuncio_id'];

        try {
            // Inicia uma transação
            $pdo->beginTransaction();

            // Excluir as denúncias relacionadas ao anúncio
            $stmt = $pdo->prepare("DELETE FROM recycle.tb_denuncias WHERE anuncio_id = :anuncio_id");
            $stmt->bindParam(':anuncio_id', $anuncio_id, PDO::PARAM_INT);
            $stmt->execute();

            // Excluir o anúncio
            $stmt = $pdo->prepare("DELETE FROM recycle.tb_anuncios WHERE id = :anuncio_id");
            $stmt->bindParam(':anuncio_id', $anuncio_id, PDO::PARAM_INT);
            $stmt->execute();

            // Confirma a transação
            $pdo->commit();

        } catch (Exception $e) {
            // Reverte a transação em caso de erro
            $pdo->rollBack();
            echo "Erro ao excluir o anúncio: " . $e->getMessage();
            exit();
        }
    } else {
        // Tratamento de erro: ID do anúncio não foi fornecido
        echo "Erro: ID do anúncio não foi fornecido.";
        exit();
    }

    // Redirecionar de volta ao painel administrativo
    header('Location: /admin-dashboard');
    exit();
});


// Rota para excluir uma denúncia
SimpleRouter::post('/admin/excluir-denuncia', function () use ($pdo) {
    if (isset($_POST['denuncia_id']) && !empty($_POST['denuncia_id'])) {
        $denuncia_id = $_POST['denuncia_id'];

        // Excluir a denúncia
        $stmt = $pdo->prepare("DELETE FROM recycle.tb_denuncias WHERE id = :denuncia_id");
        $stmt->bindParam(':denuncia_id', $denuncia_id, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        // Tratamento de erro: ID da denúncia não foi fornecido
        echo "Erro: ID da denúncia não foi fornecido.";
        exit();
    }

    // Redirecionar de volta ao painel administrativo
    header('Location: /admin-dashboard');
    exit();
});


SimpleRouter::start();
?>