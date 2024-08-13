<?php

function getUserAnnouncements($userId, $limit, $offset) {
    global $pdo;
    $stmt = $pdo->prepare('
        SELECT a.*, u.nome as usuario_nome, u.telefone as usuario_telefone 
        FROM recycle.tb_anuncios a 
        JOIN recycle.tb_users u ON a.user_id = u.id
        WHERE a.user_id = :user_id
        ORDER BY a.data_criacao DESC
        LIMIT :limit OFFSET :offset
    ');
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($announcements as &$announcement) {
        if ($announcement['imagem']) {
            $imagemData = stream_get_contents($announcement['imagem']);
            $announcement['imagem'] = base64_encode($imagemData);
        }
    }

    return $announcements;
}

function getAllAnnouncements($limit, $offset) {
    global $pdo;
    $stmt = $pdo->prepare('
        SELECT a.*, u.nome as usuario_nome, u.telefone as usuario_telefone 
        FROM recycle.tb_anuncios a 
        JOIN recycle.tb_users u ON a.user_id = u.id
        ORDER BY a.data_criacao DESC
        LIMIT :limit OFFSET :offset
    ');
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($announcements as &$announcement) {
        if ($announcement['imagem']) {
            // Converte o recurso de fluxo em uma string
            $imagemData = stream_get_contents($announcement['imagem']);
            $announcement['imagem'] = base64_encode($imagemData);
        }
    }

    return $announcements;
}

function getTotalAnnouncementsCount() {     
    global $pdo;
    $stmt = $pdo->query('SELECT COUNT(*) FROM recycle.tb_anuncios');
    return $stmt->fetchColumn();
}

function likeAnnouncement($id) {
    global $pdo;

    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }

    $user_id = $_SESSION['user_id'];

    try {
        // Verifica se o usuário já curtiu o anúncio
        $stmt = $pdo->prepare('SELECT 1 FROM recycle.tb_announcement_likes WHERE announcement_id = :announcement_id AND user_id = :user_id');
        $stmt->bindParam(':announcement_id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->fetchColumn()) {
            // Já curtiu, então descurte
            $stmt = $pdo->prepare('DELETE FROM recycle.tb_announcement_likes WHERE announcement_id = :announcement_id AND user_id = :user_id');
            $stmt->bindParam(':announcement_id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $_SESSION['success'] = 'Descurtiu o anúncio com sucesso!';
        } else {
            // Não curtiu, então curta
            $stmt = $pdo->prepare('INSERT INTO recycle.tb_announcement_likes (announcement_id, user_id) VALUES (:announcement_id, :user_id)');
            $stmt->bindParam(':announcement_id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $_SESSION['success'] = 'Curtiu o anúncio com sucesso!';
        }

    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao processar o like: ' . $e->getMessage();
    }

    header('Location: /home');
    exit();
}