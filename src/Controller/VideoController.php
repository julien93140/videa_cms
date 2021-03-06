<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UploadedVideo;
use App\Entity\ProviderVideo;
use App\Form\UploadType;
use App\Form\VideoType;
use DateTime;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Request;

/**
 * @Route("/videos")
 */
class VideoController extends Controller
{
    /**
     * @Route("/", name="video_homepage")
     */
    public function indexAction()
    {
        $providerVideos = $this->getDoctrine()->getRepository(ProviderVideo::class)->findAll();
        $uploadedVideos = $this->getDoctrine()->getRepository(UploadedVideo::class)->findAll();
        $videos = array_merge($providerVideos, $uploadedVideos);
        usort($videos, function($a, $b) {
            if ($a == $b) {
                return 0;
            }
            return $a < $b ? 1 : -1;
        });
        return $this->render('/front/video/index.html.twig', [
            'videos' => $videos,
        ]);
    }

    /**
     * @Route("/add", name="video_add")
     */
    public function addAction(Request $request){


        return $this->render('/front/video/add.html.twig');
    }

    /**
     * @Route("/add-link", name="video_add_link")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function addLinkAction(Request $request){

        $this->denyAccessUnlessGranted('ROLE_CREATOR');

        $video = new ProviderVideo();
        $form = $this->get('form.factory')->create(VideoType::class, $video);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $user = $this->getDoctrine()->getRepository(User::class)->findOneBy(array('id' => $this->getUser()->getId()));
            $video->setUser($user);
            $em = $this->getDoctrine()->getManager();
            $em->persist($video);
            $em->flush();

            $request->getSession()->getFlashBag()->add('success', 'Vidéo ajoutée !');
            return $this->redirectToRoute('video_add');
        }

        return $this->render('/front/video/creator/add.html.twig', array(
            'form' => $form->createView()
        ));
    }

    /**
     * @Route("/upload", name="video_upload")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function uploadAction(Request $request){

        $this->denyAccessUnlessGranted('ROLE_CREATOR');

        $uploadVideo = new UploadedVideo();

        $form = $this->createForm(UploadType::class, $uploadVideo);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {


            $userInfo = $this->getDoctrine()->getRepository(User::class)->findOneBy(array("id" => $this->getUser()->getId()));
            $uploadVideo->setUser($userInfo);
            $uploadVideo->setUserLikes(array());
            $uploadVideo->preUploadVideo();
            $uploadVideo->uploadVideo();

            $em = $this->getDoctrine()->getManager();
            $em->persist($uploadVideo);
            $em->flush();
            return new JsonResponse(["status" => "ok", "link" => $uploadVideo->getId()]);
        }

        return $this->render('/front/video/creator/upload.html.twig', array(
            'form' => $form->createView()
        ));
    }

    /**
     * @Route("/manage", name="video_manage")
     */
    public function manageAction(){

        $this->denyAccessUnlessGranted('ROLE_CREATOR');

        $extVideos = $this->getDoctrine()->getRepository(ProviderVideo::class)->findBy(array("user" => $this->getUser()));
        $upVideos = $this->getDoctrine()->getRepository(UploadedVideo::class)->findBy(array("user" => $this->getUser()));

        return $this->render('/front/video/creator/manage.html.twig', array(
            'extVideos' => $extVideos,
            'upVideos' => $upVideos
        ));
    }

    /**
     * @Route("/delete/{type}/{id}", name="video_delete", requirements={"id"="\d+", "type"="upload|video"})
     * @param Request $request
     * @param $type
     * @param $id
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction(Request $request, $type, $id){
        $this->denyAccessUnlessGranted('ROLE_CREATOR');

        $user = $this->getUser();
        $em = $this->getDoctrine()->getManager();

        if($type == "upload"){
            if($repo = $this->getDoctrine()->getRepository(UploadedVideo::class)->findOneBy(array("id" => $id, "user" => $user))){

                $repo->removeVideoFile();
                $em->remove($repo);
                $em->flush();
                $request->getSession()->getFlashBag()->add('success', 'Vidéo supprimée');
                return $this->redirectToRoute('video_manage');
            }
        }
        elseif($type == "video"){
            if($repo = $this->getDoctrine()->getRepository(ProviderVideo::class)->findOneBy(array("id" => $id, "user" => $user))) {

                $em->remove($repo);
                $em->flush();
                $request->getSession()->getFlashBag()->add('success', 'Vidéo supprimée');
                return $this->redirectToRoute('video_manage');
            }
        }

        throw new Exception(403);

    }

    /**
     * @Route("/edit/{type}/{id}", name="video_edit", requirements={"id"="\d+", "type"="upload|video"})
     * @param Request $request
     * @param $type
     * @param $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function editAction(Request $request, $type, $id){
        $this->denyAccessUnlessGranted('ROLE_CREATOR');

        $user = $this->getUser();

        if($type == "upload"){

            if($repo = $this->getDoctrine()->getRepository(UploadedVideo::class)->findOneBy(array("id" => $id, "user" => $user))){
                $repo->setVideoFile($repo->getVideoFile());

                $form = $this->createFormBuilder($repo)
                    ->add('title', TextType::class)
                    ->add('description', TextareaType::class)
                    ->add('public', CheckboxType::class)
                    ->add('submit', SubmitType::class)
                    ->getForm();

                $form->handleRequest($request);

                if ($form->isSubmitted()) {
                    $em = $this->getDoctrine()->getManager();
                    $em->persist($repo);
                    $em->flush();
                    $request->getSession()->getFlashBag()->add('success', 'Vidéo modifiée');
                }

                return $this->render('/front/video/creator/editUploaded.html.twig', array(
                    'form' => $form->createView()
                ));
            }
            throw new Exception(403);

        }
        elseif($type == "video"){

            if($repo = $this->getDoctrine()->getRepository(ProviderVideo::class)->findOneBy(array("id" => $id, "user" => $user))) {

                $form = $this->get("form.factory")->create(VideoType::class, $repo);

                $form->handleRequest($request);

                if ($form->isSubmitted() && $form->isValid()) {
                    $this->getDoctrine()->getManager()->flush();
                    $request->getSession()->getFlashBag()->add('success', 'Vidéo modifiée');
                }

                return $this->render('/front/video/creator/editVideo.html.twig', array(
                    'form' => $form->createView()
                ));
            }
            throw new Exception(403);
        }
        throw new Exception(403);
    }

    /**
     * @Route("/view/{id}", name="video_view")
     * @param $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function viewAction($id){

        // v = ProviderVideo
        // u = UploadedVideo

        $type = $id[0];
        $id = substr($id, 1);

        if($type == "v"){
            $video = $this->getDoctrine()->getRepository(ProviderVideo::class)->findOneBy(array("id" => $id));

            return $this->render('/front/video/viewVideo.html.twig', array(
                'video' => $video
            ));
        }
        elseif($type == "u"){
            $repo = $this->getDoctrine()->getRepository(UploadedVideo::class);
            $video = $repo->findOneBy(array("id" => $id));
            $repo->addView($id);
            $path = $video->getWebVideoPath();


            return $this->render('/front/video/viewUploaded.html.twig', array(
                'video' => $video,
                'path' => $path
            ));
        }

        throw new Exception(400);
    }

    /**
     * @Route("/like/{id}", name="video_like", requirements={"id"="\d+"})
     * @param Request $request
     * @param $id
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function likeAction(Request $request, $id){
        $this->denyAccessUnlessGranted('ROLE_USER');

        $comment = $this->getDoctrine()->getRepository(UploadedVideo::class)->findOneBy(array("id" => $id));
        $userLikes = $comment->getUserLikes();
        $likes = $comment->getLikes();
        foreach ($userLikes as $userLike){
            if ($userLike == $this->getUser()->getId())
                $liked = true;
        }

        if(isset($liked)){
            $likes -= 1;
            $userId = array_search($this->getUser()->getId(), $userLikes);
            unset($userLikes[$userId]);
        }
        else{
            $likes += 1;
            array_push($userLikes, $this->getUser()->getId());
        }

        $comment->setLikes($likes);
        $comment->setUserLikes($userLikes);

        $em = $this->getDoctrine()->getManager();
        $em->persist($comment);
        $em->flush();

        return $this->redirectToRoute($request->getUri());
    }

    /**
     * @Route("/channel/{user}", name="video_channel")
     */
    public function channelAction($user){

        $user = $this->getDoctrine()->getRepository(User::Class)->findOneBy(array("usernameCanonical" => $user));
        $providerVideos = $this->getDoctrine()->getRepository(ProviderVideo::class)->findBy(array("user" => $user));
        $uploadedVideos = $this->getDoctrine()->getRepository(UploadedVideo::class)->findBy(array("user" => $user));

        $videos = array_merge($providerVideos, $uploadedVideos);
        usort($videos, function($a, $b) {
            if ($a == $b) {
                return 0;
            }
            return $a < $b ? 1 : -1;
        });

        return $this->render('/front/video/channel.html.twig', array(
            'user' => $user,
            'videos' => $videos
        ));

    }

}
