<?php

function getUser() {
    global $pdo;
    if (!checkLogin()) {
        return null;
    }
    
    $stmt = $pdo->prepare('SELECT id, nome, email, localizacao, ramo_mercado, descricao_adicional, telefone, foto FROM recycle.tb_users WHERE email = :email');
    $stmt->execute(['email' => $_SESSION['user_email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['foto']) {
        // Converte o recurso de fluxo em uma string e depois para base64
        $fotoData = stream_get_contents($user['foto']);
        $user['foto'] = base64_encode($fotoData);
    }

    return $user;
}

function findUserById($id, $pdo) {
    try {
        $stmt = $pdo->prepare('SELECT id, nome, email, telefone, localizacao, ramo_mercado, descricao_adicional, foto FROM recycle.tb_users WHERE id = :id');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['foto']) {
            // Converte o recurso de fluxo em uma string e depois para base64
            $fotoData = stream_get_contents($user['foto']);
            $user['foto'] = base64_encode($fotoData);
        }

        return $user;
    } catch (PDOException $e) {
        error_log($e->getMessage());
        return null;
    }
}



function updateUserById($id, $nome, $telefone) {
    global $pdo;
    $stmt = $pdo->prepare("
        UPDATE recycle.tb_users
        SET nome = :nome, telefone = :telefone
        WHERE id = :id
    ");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute([
        'nome' => $nome,
        'telefone' => $telefone
    ]);
}

function updateUser($email, $nome, $newEmail, $telefone) {
    global $pdo;

    $stmt = $pdo->prepare("UPDATE  recycle.tb_users SET nome = :nome, email = :email, telefone = :telefone WHERE email = :email");
    $stmt->execute([
        'nome' => $nome,
        'email' => $newEmail,
        'telefone' => $telefone
    ]);
}

function likeProfile($profileId) {
    global $pdo;

    // Verificar se o usuário está logado
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    $userId = $_SESSION['user_id'];

    try {
        // Verificar se o usuário já curtiu o perfil
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM recycle.profile_likes WHERE user_id = :user_id AND liked_user_id = :profile_id');
        $stmt->execute(['user_id' => $userId, 'profile_id' => $profileId]);
        $alreadyLiked = $stmt->fetchColumn();

        if ($alreadyLiked) {
            // Se já curtiu, talvez você queira desfazer a curtida ou apenas retornar
            $_SESSION['error'] = 'Você já curtiu este perfil.';
            
        } else {
            // Adicionar like ao perfil
            $stmt = $pdo->prepare('INSERT INTO recycle.profile_likes (user_id, liked_user_id) VALUES (:user_id, :profile_id)');
            $stmt->execute(['user_id' => $userId, 'profile_id' => $profileId]);
            $_SESSION['success'] = 'Perfil curtido com sucesso!';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao curtir o perfil: ' . $e->getMessage();
    }

    // Redirecionar de volta ao perfil
    header('Location: /perfil/' . $profileId);
    exit();
}

function hasUserLikedProfile($currentUserId, $profileUserId, $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS has_liked
            FROM recycle.profile_likes
            WHERE user_id = :current_user_id AND liked_user_id = :profile_user_id
        ");
        $stmt->bindParam(':current_user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':profile_user_id', $profileUserId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['has_liked'] > 0;
    } catch (PDOException $e) {
        error_log('Error in hasUserLikedProfile: ' . $e->getMessage());
        return false;
    }
}


function findLikedAnnouncements($userId, $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT a.id, a.assunto, a.descricao, a.preco, a.data_criacao, a.imagem
            FROM recycle.tb_announcement_likes al
            JOIN recycle.tb_anuncios a ON al.announcement_id = a.id
            WHERE al.user_id = :user_id AND ativo <> false
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Converte imagens de anúncios para base64
        foreach ($announcements as &$announcement) {
            if ($announcement['imagem']) {
                // Converte o conteúdo binário da imagem para base64
                $imageData = stream_get_contents($announcement['imagem']);
                $announcement['imagem'] = base64_encode($imageData);
            }
        }

        return $announcements;
    } catch (PDOException $e) {
        error_log('Error fetching liked announcements: ' . $e->getMessage());
        return [];
    }
}

